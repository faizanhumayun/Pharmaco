<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Opening\Actions\CorrectOpeningBalance;
use App\Domain\Opening\Actions\FinalizeOpeningBalance;
use App\Domain\Opening\Actions\SaveOpeningBalanceDraft;
use App\Domain\Opening\OpeningBalanceCalculator;
use App\Domain\Opening\OpeningField;
use App\Enums\AccountCode;
use App\Exceptions\LedgerException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CorrectOpeningBalanceRequest;
use App\Http\Requests\Admin\FinalizeOpeningBalanceRequest;
use App\Http\Requests\Admin\SaveOpeningBalanceRequest;
use App\Models\Business;
use App\Models\OpeningBalance;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class OpeningBalanceController extends Controller
{
    public const CONFIRMATION = 'I confirm that these values represent the actual opening '
        . 'financial position of this business at the time it started using the system.';

    public function __construct(private readonly OpeningBalanceCalculator $calculator) {}

    /** Step 2 — the figures. */
    public function edit(Business $business): View
    {
        $opening = $business->openingBalance;

        $opening
            ? $this->authorize('update', $opening)
            : $this->authorize('create', [OpeningBalance::class, $business]);

        return view('admin.opening.edit', [
            'business' => $business,
            'opening' => $opening,
            'fields' => OpeningField::all(),
            'values' => $this->currentValues($opening),
        ]);
    }

    public function update(SaveOpeningBalanceRequest $request, Business $business, SaveOpeningBalanceDraft $action): RedirectResponse
    {
        $action->handle($business, $request->validated(), $request->user());

        return redirect()
            ->route('admin.businesses.opening.review', $business)
            ->with('status', 'Draft saved. Review the position before finalizing.');
    }

    /** Step 3 — review, where the position is shown before anything is committed. */
    public function review(Business $business): View
    {
        $opening = $business->openingBalance;

        abort_if($opening === null, 404);
        $this->authorize('view', $opening);

        return view('admin.opening.review', [
            'business' => $business,
            'opening' => $opening,
            'position' => $this->calculator->fromRecord($opening),
            'fields' => OpeningField::all(),
            'confirmation' => self::CONFIRMATION,
        ]);
    }

    public function finalize(FinalizeOpeningBalanceRequest $request, Business $business, FinalizeOpeningBalance $action): RedirectResponse
    {
        $opening = $business->openingBalance;

        // The explanation is captured with the confirmation, so an unusual
        // position cannot be waved through and explained "later".
        if ($request->filled('notes')) {
            $opening->forceFill(['notes' => $request->string('notes')->toString()])->save();
        }

        try {
            $action->handle($opening->fresh(), $request->user(), self::CONFIRMATION);
        } catch (LedgerException $e) {
            throw ValidationException::withMessages(['notes' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.businesses.opening.show', $business)
            ->with('status', 'Opening balance finalized. This business can now record activity.');
    }

    public function show(Business $business): View
    {
        $opening = $business->openingBalance;

        abort_if($opening === null, 404);
        $this->authorize('view', $opening);

        $opening->load(['lines.account', 'creator', 'finalizer', 'transaction.lines.account']);

        return view('admin.opening.show', [
            'business' => $business,
            'opening' => $opening,
            'position' => $this->calculator->fromRecord($opening),
            'fields' => OpeningField::all(),
            'corrections' => $opening->transaction
                ? \App\Models\Transaction::forBusiness($business)
                    ->where('source_type', $opening->getMorphClass())
                    ->where('source_id', $opening->id)
                    ->where('id', '!=', $opening->transaction_id)
                    ->with('creator')
                    ->latest('business_date')
                    ->get()
                : collect(),
        ]);
    }

    public function correct(CorrectOpeningBalanceRequest $request, Business $business, CorrectOpeningBalance $action): RedirectResponse
    {
        try {
            $action->handle(
                $business->openingBalance,
                AccountCode::from($request->string('account')->toString()),
                Money::of($request->string('amount')->toString()),
                $request->string('reason')->toString(),
                $request->user(),
            );
        } catch (LedgerException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        return back()->with('status', 'Correction posted. The original opening entry is unchanged.');
    }

    /** @return array<string, string> */
    private function currentValues(?OpeningBalance $opening): array
    {
        if ($opening === null) {
            return collect(OpeningField::all())
                ->mapWithKeys(fn (OpeningField $f) => [$f->key() => ''])
                ->all();
        }

        $opening->loadMissing('lines.account');

        return collect(OpeningField::all())
            ->mapWithKeys(fn (OpeningField $f) => [
                $f->key() => $opening->lines
                    ->firstWhere('account.code', $f->key())?->amount->toDecimal() ?? '',
            ])
            ->all();
    }
}

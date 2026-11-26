<?php

namespace App\Http\Controllers\Business;

use App\Domain\Businesses\Actions\UpdateBusiness;
use App\Enums\ReceiptFormat;
use App\Http\Controllers\Controller;
use App\Models\Business;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The settings a business looks after itself.
 *
 * Its own details and how its counter behaves — the things an owner changes
 * without asking anyone. What the platform decides for them, and what the
 * books would be rewritten by, stays in the back office: the name the platform
 * knows it by, its kind, its currency and its timezone all belong there,
 * because a timezone is which calendar day a sale fell on.
 */
class SettingsController extends Controller
{
    /** The page's sections, down its side as on the Daily Log. */
    public const SECTIONS = ['details', 'printing', 'colour', 'expiry', 'payments', 'fixed'];

    public function edit(Request $request, Business $business): View
    {
        $this->authorize('configure', $business);

        return view('business.settings.edit', [
            'business' => $business,
            'section' => $this->section($request),
            'formats' => ReceiptFormat::cases(),
            // The platform's list, and which of it this business has on.
            'paymentMethods' => \App\Models\PaymentMethod::query()->active()->orderBy('sort_order')->get()->groupBy('kind'),
            'mine' => \App\Models\BusinessPaymentMethod::forBusiness($business)->get()->keyBy('payment_method_id'),
        ]);
    }

    /** Which wallets and banks this business is paid through. */
    public function paymentMethods(
        Request $request,
        Business $business,
        \App\Domain\Businesses\Actions\SetPaymentMethods $action,
    ): RedirectResponse {
        $this->authorize('configure', $business);

        $data = $request->validate([
            'methods' => ['nullable', 'array'],
            'methods.*' => ['integer'],
            'titles' => ['nullable', 'array'],
            'titles.*' => ['nullable', 'string', 'max:160'],
        ]);

        $action->handle($business, $data['methods'] ?? [], $data['titles'] ?? [], $request->user());

        return redirect()
            ->route('businesses.settings.edit', [$business, 'section' => 'payments'])
            ->with('status', 'Payment methods saved.');
    }

    public function update(Request $request, Business $business, UpdateBusiness $action): RedirectResponse
    {
        $this->authorize('configure', $business);

        $data = $request->validate([
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:160'],
            'ntn' => ['nullable', 'string', 'max:40'],
            'receipt_format' => ['required', Rule::enum(ReceiptFormat::class)],
            'expiry_alert_months' => ['nullable', 'integer', 'min:1', 'max:36'],
            'accent' => ['nullable', Rule::enum(\App\Enums\AccentColour::class)],
            'receipt_footer' => ['nullable', 'string', 'max:4000'],
            'pos_net_bill' => ['nullable', 'boolean'],
            'pos_print' => ['nullable', 'boolean'],
            'pos_balance' => ['nullable', 'boolean'],
        ]);

        // An unticked box sends nothing, and nothing means off.
        // Written in an editor: kept to line breaks, bold, italic and underline.
        $data['receipt_footer'] = \App\Support\SlipText::clean($data['receipt_footer'] ?? null);

        foreach (['pos_net_bill', 'pos_print', 'pos_balance'] as $switch) {
            $data[$switch] = $request->boolean($switch);
        }

        $action->handle($business, $data);

        // Back to the section that was saved, not to the top.
        return redirect()
            ->route('businesses.settings.edit', [$business, 'section' => $this->section($request)])
            ->with('status', 'Settings saved.');
    }

    /** The section asked for, or the first. */
    private function section(Request $request): string
    {
        $asked = (string) $request->input('section');

        return in_array($asked, self::SECTIONS, true) ? $asked : 'details';
    }
}

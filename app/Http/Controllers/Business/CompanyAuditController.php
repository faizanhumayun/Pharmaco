<?php

namespace App\Http\Controllers\Business;

use App\Domain\Stock\Audits\Actions\CountAuditItem;
use App\Domain\Stock\Audits\Actions\DecideCompanyAudit;
use App\Domain\Stock\Audits\Actions\StartCompanyAudit;
use App\Domain\Stock\Audits\CompanyAuditReport;
use App\Domain\Stock\Audits\CompanyAuditSchedule;
use App\Domain\Stock\StockLedger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Business\CountAuditItemRequest;
use App\Models\Business;
use App\Models\Company;
use App\Models\CompanyAudit;
use App\Models\CompanyAuditItem;
use App\Models\CompanyProduct;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Company stock audit: one company's rack counted in one go, each company at
 * least once a month, and read as a report of what the stock was worth before
 * and after. Counting is open to whoever may verify stock; deciding what
 * happens to the stock is the owner's.
 */
class CompanyAuditController extends Controller
{
    public function index(Request $request, Business $business, CompanyAuditSchedule $schedule, CompanyAuditReport $report, StockLedger $stock): View
    {
        $this->authorize('verifyStock', $business);

        $companies = $schedule->for($business);

        $search = trim((string) $request->string('q'));
        $shown = $search === '' ? $companies : $companies->filter(
            fn ($r) => str_contains(mb_strtolower($r['company']->name), mb_strtolower($search))
        )->values();

        // The company chosen, else the first one due.
        $chosen = $companies->firstWhere('company.id', $request->integer('company')) ?? $shown->first();

        $summary = null;
        if ($chosen) {
            $ids = CompanyProduct::forBusiness($business)->where('company_id', $chosen['company']->id)->pluck('id')->all();
            $held = $stock->piecesByProduct($business, null, $ids)->filter(fn ($p) => (int) $p > 0);
            $products = CompanyProduct::forBusiness($business)->whereIn('id', $held->keys())->get();
            $from = $chosen['last']?->business_date->toDateString() ?? $business->opening_date?->toDateString();

            $summary = [
                'packs' => $stock->onHandByProduct($business, null, $held->keys()->all())->sum(),
                'loose' => $stock->looseByProduct($business, null, $held->keys()->all())->sum(),
                'mrp' => Money::sum($products->map(fn (CompanyProduct $p) => $report->value(
                    $p->mrp ?? Money::zero(), (int) $held[$p->id], max(1, (int) $p->units_per_pack)
                ))),
                'from' => $from,
                'till' => $report->till($business, $held->keys()->all(), $from, $business->today()->toDateString()),
                'catalogue' => count($ids),
            ];
        }

        return view('business.stock.company-audits.index', [
            'business' => $business,
            'companies' => $shown,
            'total' => $companies->count(),
            // Due and not started: one being counted is already in hand.
            'due' => $companies->filter(fn ($r) => $r['due'] && ! $r['open'])->values(),
            'chosen' => $chosen,
            'summary' => $summary,
            'search' => $search,
            'recent' => CompanyAudit::forBusiness($business)
                ->with(['company', 'starter'])
                ->latest('id')
                ->take(15)
                ->get(),
        ]);
    }

    public function store(Request $request, Business $business, StartCompanyAudit $start): RedirectResponse
    {
        $this->authorize('verifyStock', $business);

        $data = $request->validate([
            'company_id' => ['required', 'integer'],
            'by_batch' => ['nullable', 'boolean'],
            'include_empty' => ['nullable', 'boolean'],
        ]);

        $company = Company::forBusiness($business)->findOrFail($data['company_id']);

        try {
            $audit = $start->handle($business, $company, $request->user(), $request->boolean('by_batch'), $request->boolean('include_empty'));
        } catch (RuntimeException $e) {
            return back()->withErrors(['company_id' => $e->getMessage()]);
        }

        return redirect()->route('businesses.company-audits.show', [$business, $audit]);
    }

    /** The sheet while counting; the report once counting has finished. */
    public function show(Business $business, CompanyAudit $companyAudit, CompanyAuditReport $report): View
    {
        $this->authorize('verifyStock', $business);
        abort_if($companyAudit->business_id !== $business->id, 404);

        $companyAudit->load(['company', 'starter', 'submitter', 'decider', 'device', 'transaction']);
        $rows = $report->rows($companyAudit);

        if ($companyAudit->isCounting()) {
            return view('business.stock.company-audits.sheet', [
                'business' => $business,
                'audit' => $companyAudit,
                'rows' => $rows->map(fn ($r) => $this->rowPayload($business, $companyAudit, $r))->values(),
                'totals' => $this->totalsPayload($report->summary($companyAudit, $rows)),
                'canEditProducts' => request()->user()->can('editProducts', $business),
                // The types a product can be, as Add product offers them.
                'forms' => array_map(fn (\App\Enums\DosageForm $f) => $f->label(), \App\Enums\DosageForm::cases()),
            ]);
        }

        return view('business.stock.company-audits.report', [
            'business' => $business,
            'audit' => $companyAudit,
            'rows' => $rows,
            'summary' => $report->summary($companyAudit, $rows),
            'previous' => CompanyAudit::forBusiness($business)
                ->where('company_id', $companyAudit->company_id)
                ->where('id', '<', $companyAudit->id)
                ->done()
                ->latest('id')
                ->take(6)
                ->get()
                ->map(fn (CompanyAudit $a) => ['audit' => $a, 'change' => $report->change($a)]),
            'canDecide' => request()->user()->can('configure', $business),
            // The report's sections, down its side as in Settings.
            'section' => in_array(request()->query('section'), ['summary', 'till', 'differences', 'matched', 'uncounted', 'decide'], true)
                ? request()->query('section') : 'summary',
        ]);
    }

    /** One product counted; answered as JSON for the sheet, which saves line by line. */
    public function count(CountAuditItemRequest $request, Business $business, CompanyAudit $companyAudit, CompanyAuditItem $companyAuditItem, CountAuditItem $action, CompanyAuditReport $report): JsonResponse|RedirectResponse
    {
        abort_if($companyAudit->business_id !== $business->id, 404);

        $data = $request->countData();

        // Prices and pack size are the catalogue's: changed here only by
        // someone who may change products, else counted against as they are.
        if ($data['product'] !== [] && ! $request->user()->can('editProducts', $business)) {
            $data['product'] = [];
        }

        try {
            $action->handle($companyAudit, $companyAuditItem, $data, $request->user());
        } catch (RuntimeException|\App\Exceptions\LedgerException $e) {
            throw ValidationException::withMessages(['count' => $e->getMessage()]);
        }

        $rows = $report->rows($companyAudit);
        $row = $rows->firstWhere('item.id', $companyAuditItem->id);

        if (! $request->expectsJson()) {
            return back();
        }

        return response()->json([
            'row' => $this->rowPayload($business, $companyAudit, $row),
            'totals' => $this->totalsPayload($report->summary($companyAudit, $rows)),
        ]);
    }

    /** A line with nothing in the records, off the sheet. Answered as JSON. */
    public function removeItem(Request $request, Business $business, CompanyAudit $companyAudit, CompanyAuditItem $companyAuditItem, \App\Domain\Stock\Audits\Actions\RemoveAuditItem $action, CompanyAuditReport $report): JsonResponse
    {
        $this->authorize('verifyStock', $business);
        abort_if($companyAudit->business_id !== $business->id, 404);

        try {
            $action->handle($companyAudit, $companyAuditItem);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['count' => $e->getMessage()]);
        }

        return response()->json(['totals' => $this->totalsPayload($report->summary($companyAudit))]);
    }

    public function finish(Request $request, Business $business, CompanyAudit $companyAudit, DecideCompanyAudit $decide): RedirectResponse
    {
        $this->authorize('verifyStock', $business);

        return $this->attempt(fn () => $decide->submit($companyAudit, $request->user()), $business, $companyAudit, 'Audit finished. It is waiting for the owner to approve it.');
    }

    public function reopen(Request $request, Business $business, CompanyAudit $companyAudit, DecideCompanyAudit $decide): RedirectResponse
    {
        $this->authorize('verifyStock', $business);

        return $this->attempt(fn () => $decide->reopen($companyAudit), $business, $companyAudit, 'Sent back for counting. Change any line, then finish again.');
    }

    public function approve(Request $request, Business $business, CompanyAudit $companyAudit, DecideCompanyAudit $decide): RedirectResponse
    {
        $this->authorize('configure', $business);

        return $this->attempt(fn () => $decide->approve($companyAudit, $request->user(), $request->input('reason')), $business, $companyAudit, 'Approved. Stock now matches what was counted.');
    }

    public function close(Request $request, Business $business, CompanyAudit $companyAudit, DecideCompanyAudit $decide): RedirectResponse
    {
        $this->authorize('configure', $business);

        return $this->attempt(fn () => $decide->close($companyAudit, $request->user(), $request->input('reason')), $business, $companyAudit, 'Closed. Stock was not changed; the counts stay in the records.');
    }

    private function attempt(callable $do, Business $business, CompanyAudit $audit, string $done): RedirectResponse
    {
        abort_if($audit->business_id !== $business->id, 404);

        try {
            $do();
        } catch (RuntimeException $e) {
            return back()->withErrors(['audit' => $e->getMessage()]);
        }

        return redirect()->route('businesses.company-audits.show', [$business, $audit])->with('status', $done);
    }

    /** A sheet line as the page's script reads it. */
    private function rowPayload(Business $business, CompanyAudit $audit, array $r): array
    {
        $p = $r['product'];
        $say = fn (?int $pieces) => $pieces === null ? null : $p->stockLabel(intdiv(abs($pieces), $r['per']), abs($pieces) % $r['per']);

        return [
            'id' => $r['item']->id,
            'url' => route('businesses.company-audits.count', [$business, $audit, $r['item']]),
            'remove_url' => route('businesses.company-audits.remove-item', [$business, $audit, $r['item']]),
            'name' => $p->label(),
            'pack' => $p->pack_size,
            'per' => $r['per'],
            'noun' => $p->pieceNoun(),
            'rate' => ($r['cost'])->format(),
            'mrp' => ($r['mrp'])->format(),
            // As the product holds them now, for the count window to edit.
            'rate_raw' => ($p->purchase_rate ?? $p->trade_price)?->toDecimal() ?? '',
            'mrp_raw' => $p->mrp?->toDecimal() ?? '',
            'pack_raw' => $p->pack_size ?? '',
            'brand_raw' => $p->brand_name ?? '',
            'strength_raw' => $p->strength ?? '',
            'form_raw' => $p->dosage_form ?? '',
            'now_packs' => $r['now_packs'],
            'now_loose' => $r['now_loose'],
            'now' => $p->stockLabel($r['now_packs'], $r['now_loose']),
            'batches' => $r['batches'],
            'sold' => $r['sold_pieces'] ? $say($r['sold_pieces']) : null,
            'last_sold' => $r['last_sold']?->format('j M'),
            'counted' => $r['counted'],
            'matched' => $r['matched'],
            'expected' => $say($r['expected_pieces']),
            'found' => $say($r['counted_pieces']),
            // The same, packs and loose apart: the sheet shows them on two lines.
            'expected_split' => $this->split($p, $r['expected_pieces'] ?? null, $r['per']),
            'found_split' => $this->split($p, $r['counted_pieces'] ?? null, $r['per']),
            'now_split' => $this->split($p, $r['now_packs'] * $r['per'] + $r['now_loose'], $r['per']),
            'diff' => $r['diff_pieces'],
            'diff_label' => $r['diff_pieces'] ? ($r['diff_pieces'] < 0 ? '−' : '+').$say($r['diff_pieces']) : null,
            'diff_value' => $r['diff_value']?->isZero() === false ? ($r['diff_value']->isNegative() ? '−' : '+').$r['diff_value']->absolute()->format() : null,
            'counted_by' => $r['count']?->counter?->name,
            'note' => $r['count']?->note,
        ];
    }

    /** "31 packs" and "8 loose tablets", for two lines; loose is null when there is none. */
    private function split(\App\Models\CompanyProduct $p, ?int $pieces, int $per): ?array
    {
        if ($pieces === null) {
            return null;
        }

        $packs = intdiv($pieces, $per);
        $loose = $pieces % $per;

        return [
            'packs' => $p->stockLabel($packs),
            'loose' => $loose ? $p->stockLabel(0, $loose) : null,
        ];
    }

    private function totalsPayload(array $s): array
    {
        return [
            'products' => $s['products'],
            'counted' => $s['counted'],
            'matched' => $s['matched'],
            'short_count' => $s['short_count'],
            'extra_count' => $s['extra_count'],
            'records' => $s['records']->format(),
            'found' => $s['found']->format(),
            // What approval would do to the stock value: -1 down, 1 up, 0 none.
            'change' => $s['change']->absolute()->format(),
            'change_sign' => $s['change']->isNegative() ? -1 : ($s['change']->isPositive() ? 1 : 0),
            'change_percent' => $s['change_percent'] === null ? null : abs($s['change_percent']),
            'short' => $s['short']->absolute()->format(),
            'short_mrp' => $s['short_mrp']->absolute()->format(),
            'extra' => $s['extra']->format(),
            'extra_mrp' => $s['extra_mrp']->format(),
            // Goods worth more at cost than they sell for: a price to check.
            'cost_above_mrp' => $s['extra']->greaterThan($s['extra_mrp']) || $s['short']->absolute()->greaterThan($s['short_mrp']->absolute()),
        ];
    }
}

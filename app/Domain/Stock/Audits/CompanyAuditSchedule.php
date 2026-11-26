<?php

namespace App\Domain\Stock\Audits;

use App\Domain\Stock\StockLedger;
use App\Models\Business;
use App\Models\Company;
use App\Models\CompanyAudit;
use App\Models\CompanyProduct;
use App\Models\PosBillLine;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * The monthly round: every company that holds stock, and when its rack was
 * last audited.
 *
 * Due is a reminder, never a block — selling and receiving go on whatever it
 * says. A company is due when it has never been audited, or when its last
 * finished audit is CompanyAudit::EVERY_DAYS or more days old.
 */
class CompanyAuditSchedule
{
    public function __construct(private readonly StockLedger $stock) {}

    /** @return Collection<int, array<string, mixed>> */
    public function for(Business $business): Collection
    {
        $today = $business->today();
        $pieces = $this->stock->piecesByProduct($business)->filter(fn ($p) => (int) $p !== 0);

        $products = CompanyProduct::forBusiness($business)
            ->whereIn('id', $pieces->keys())
            ->get(['id', 'company_id', 'units_per_pack', 'purchase_rate', 'trade_price', 'mrp'])
            ->groupBy('company_id');

        $audits = CompanyAudit::forBusiness($business)->orderByDesc('business_date')->orderByDesc('id')->get()->groupBy('company_id');

        return Company::forBusiness($business)
            ->whereIn('id', $products->keys())
            ->withCount('products')
            ->get()
            ->map(function (Company $company) use ($products, $pieces, $audits, $today) {
                $held = $products->get($company->id, collect());
                $value = Money::sum($held->map(function (CompanyProduct $p) use ($pieces) {
                    $per = max(1, (int) $p->units_per_pack);
                    $n = max(0, (int) $pieces[$p->id]);
                    $cost = $p->purchase_rate ?? $p->trade_price ?? Money::zero();

                    return $cost->times(intdiv($n, $per))->plus(PosBillLine::piece($cost, $per)->times($n % $per));
                }));

                $mine = $audits->get($company->id, collect());
                $last = $mine->first(fn (CompanyAudit $a) => $a->status !== CompanyAudit::COUNTING);
                $next = $last?->business_date->copy()->addDays(CompanyAudit::EVERY_DAYS);

                return [
                    'company' => $company,
                    'products' => $held->count(),
                    'value' => $value,
                    'last' => $last,
                    'next' => $next,
                    'due' => $next === null || $today->gte($next),
                    // An audit still being counted or waiting for approval.
                    'open' => $mine->first(fn (CompanyAudit $a) => in_array($a->status, [CompanyAudit::COUNTING, CompanyAudit::SUBMITTED], true)),
                ];
            })
            // Due first, the longest overdue first among them, then by value.
            ->sortBy(fn ($r) => [$r['due'] ? 0 : 1, $r['last']?->business_date?->timestamp ?? 0, -(float) $r['value']->toDecimal()])
            ->values();
    }

    /**
     * Where the month's round stands, each company holding stock in exactly
     * one place, so the four add up to the whole: audited (decided within the
     * month), being counted, counted and waiting for approval, or not started.
     *
     * @return array{companies: int, audited: int, counting: int, waiting: int, not_started: int}
     */
    public function summary(Business $business): array
    {
        $rows = $this->for($business);
        $state = fn (array $r) => match (true) {
            $r['open']?->status === CompanyAudit::COUNTING => 'counting',
            $r['open']?->status === CompanyAudit::SUBMITTED => 'waiting',
            ! $r['due'] => 'audited',
            default => 'not_started',
        };
        $counts = $rows->countBy($state);

        return [
            'companies' => $rows->count(),
            'audited' => $counts['audited'] ?? 0,
            'counting' => $counts['counting'] ?? 0,
            'waiting' => $counts['waiting'] ?? 0,
            'not_started' => $counts['not_started'] ?? 0,
        ];
    }

    /** How many companies holding stock are due and not yet started, for the bell. */
    public function dueCount(Business $business): int
    {
        return $this->summary($business)['not_started'];
    }
}

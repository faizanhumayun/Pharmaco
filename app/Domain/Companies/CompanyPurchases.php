<?php

namespace App\Domain\Companies;

use App\Enums\DocumentStatus;
use App\Models\Business;
use App\Models\PurchaseLine;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * What each company has billed and been paid, from the invoices themselves.
 *
 * Posted days only. A draft day has not reached the ledger, so counting it here
 * would put these figures and the company's balance on different footings and
 * invite the reader to subtract one from the other.
 *
 * These are not a second source of truth for what is owed — that is the
 * company's sub-ledger, which also carries opening balances and any payment
 * made without an invoice against it. This says what the invoices say.
 */
class CompanyPurchases
{
    /**
     * Keyed by company id.
     *
     * @return Collection<int, array{invoices: int, billed: Money, paid: Money, outstanding: Money, last: ?string}>
     */
    public function totals(Business $business): Collection
    {
        return PurchaseLine::query()
            ->whereHas('dailyEntry', fn ($q) => $q->forBusiness($business)
                ->where('status', DocumentStatus::Posted))
            ->whereNotNull('company_id')
            ->with('dailyEntry:id,business_date')
            ->get()
            ->groupBy('company_id')
            ->map(function (Collection $lines) {
                $billed = Money::sum($lines->pluck('amount'));
                $paid = Money::sum($lines->pluck('paid'));

                return [
                    'invoices' => $lines->count(),
                    'billed' => $billed,
                    'paid' => $paid,
                    'outstanding' => $billed->minus($paid),
                    'last' => $lines
                        ->map(fn (PurchaseLine $l) => $l->dailyEntry?->business_date)
                        ->filter()
                        ->max()?->format('j M Y'),
                ];
            });
    }

    /** An empty row, so a company with no invoices still reads as figures. */
    public static function none(): array
    {
        return [
            'invoices' => 0,
            'billed' => Money::zero(),
            'paid' => Money::zero(),
            'outstanding' => Money::zero(),
            'last' => null,
        ];
    }
}

<?php

namespace App\Domain\Daily;

use App\Domain\Daily\Actions\AmendDailyEntry;
use App\Domain\Daily\Actions\PostDailyEntry;
use App\Domain\Daily\Actions\SaveDailyEntry;
use App\Exceptions\LedgerException;
use App\Models\Business;
use App\Models\DailyEntry;
use App\Models\User;
use Closure;
use Illuminate\Support\Carbon;

/**
 * Adds something to a day from outside the daily form.
 *
 * The day's entry is the financial document, so an expense added on the
 * Expenses page and a bill rung up on the till both end up there, saved the
 * way the form would save it: the day as it stands, plus the new thing.
 *
 * A posted day is amended — its old postings reversed and replaced, the change
 * on the record. A draft stays a draft. A day with nothing on it yet gets an
 * entry, posted, so what happened is in the books at once. A closed day is
 * refused; it has to be reopened first.
 */
class DayWriter
{
    public function __construct(
        private readonly SaveDailyEntry $save,
        private readonly PostDailyEntry $post,
        private readonly AmendDailyEntry $amend,
    ) {}

    /**
     * @param  Closure(array<string, mixed>): array<string, mixed>  $change  the day as entered, returned with the addition
     */
    public function write(Business $business, Carbon $date, Closure $change, User $by): DailyEntry
    {
        if ($business->isDayClosed($date)) {
            throw new LedgerException(
                $date->format('D d M Y') . ' is closed. Reopen it on its closing page first.'
            );
        }

        $entry = $this->entryFor($business, $date);
        $data = $change($entry ? $this->asEntered($entry) : $this->blankDay($date));

        if ($entry === null) {
            return $this->post->handle($this->save->handle($business, $data, $by), $by);
        }

        return $entry->isEditable()
            ? $this->save->handle($business, $data, $by)
            : $this->amend->handle($entry, $data, $by);
    }

    public function entryFor(Business $business, Carbon $date): ?DailyEntry
    {
        return DailyEntry::forBusiness($business)
            ->where('business_date', $date->toDateString())
            ->with(['purchaseLines.company', 'saleLines.pharmacy', 'expenseLines.category'])
            ->first();
    }

    /**
     * The day exactly as the daily form would send it back unchanged: every
     * figure, and every purchase, sale and expense line.
     *
     * @return array<string, mixed>
     */
    public function asEntered(DailyEntry $entry): array
    {
        $data = [
            'business_date' => $entry->business_date->toDateString(),
            'company_note' => $entry->company_note,
            'notes' => $entry->notes,
            // The form types the day's total and derives credit from it.
            'sale_total' => $entry->totalSales()->toDecimal(),
        ];

        foreach (DailyEntry::MONEY_FIELDS as $field) {
            $data[$field] = $entry->{$field}->toDecimal();
        }

        $data['purchases'] = $entry->purchaseLines->map(fn ($l) => [
            'company' => $l->company?->name ?? $l->company_name ?? '',
            'order_id' => $l->order_id,
            'invoice_no' => $l->invoice_no ?? '',
            'amount' => $l->amount->toDecimal(),
            'paid' => $l->paid->toDecimal(),
        ])->values()->all();

        $data['sales'] = $entry->saleLines->map(fn ($l) => [
            'pharmacy' => $l->pharmacy?->name ?? $l->pharmacy_name ?? '',
            'invoice_no' => $l->invoice_no ?? '',
            'amount' => $l->amount->toDecimal(),
            'received' => $l->received->toDecimal(),
        ])->values()->all();

        $data['expenses'] = $entry->expenseLines->map(fn ($l) => [
            'category' => $l->category?->name ?? '',
            'description' => $l->description,
            'amount' => $l->amount->toDecimal(),
        ])->values()->all();

        return $data;
    }

    /** @return array<string, mixed> */
    public function blankDay(Carbon $date): array
    {
        return [
            'business_date' => $date->toDateString(),
            ...array_fill_keys(DailyEntry::MONEY_FIELDS, '0.00'),
            'purchases' => [],
            'sales' => [],
            'expenses' => [],
        ];
    }
}

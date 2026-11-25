<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Support\Concerns\BelongsToBusiness;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sale over the counter.
 *
 * The financial record is still the day's entry: a bill puts one sale line on
 * it, carrying the bill's total and what was taken in cash. This holds the
 * detail behind that line, so the day's gross profit is the sum of real
 * margins rather than a figure typed from memory.
 */
class PosBill extends Model
{
    use BelongsToBusiness;
    use \App\Support\Concerns\RecordsDevice;

    protected $fillable = [
        'business_id', 'business_date', 'daily_entry_id', 'bill_no', 'daily_no', 'customer_name', 'pharmacy_id', 'business_payment_method_id',
        'total', 'discount', 'received', 'cost', 'note', 'created_by',
        'as_net_bill', 'asked_for_print', 'asked_for_balance',
    ];

    protected function casts(): array
    {
        return [
            'as_net_bill' => 'boolean',
            'asked_for_print' => 'boolean',
            'asked_for_balance' => 'boolean',
            'business_date' => 'date',
            'bill_no' => 'integer',
            'daily_no' => 'integer',
            'total' => MoneyCast::class,
            'discount' => MoneyCast::class,
            'received' => MoneyCast::class,
            'cost' => MoneyCast::class,
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PosBillLine::class);
    }

    /** The customer this bill is for. The name is kept beside it as it was printed. */
    public function pharmacy(): BelongsTo
    {
        return $this->belongsTo(Pharmacy::class);
    }

    /** How the money was taken; null when it was cash. */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(BusinessPaymentMethod::class, 'business_payment_method_id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(PosReturn::class);
    }

    /** Every time the bill was corrected, oldest first. */
    public function revisions(): HasMany
    {
        return $this->hasMany(PosBillRevision::class)->orderBy('revision_no');
    }

    /** What has come back against this bill, at the price it was sold for. */
    public function returned(): Money
    {
        return Money::sum($this->returns->pluck('total'));
    }

    /** Of what came back, the part taken off the customer's account rather than refunded. */
    public function returnedOnAccount(): Money
    {
        return Money::sum($this->returns->map(fn (PosReturn $r) => $r->total->minus($r->refunded)));
    }

    public function dailyEntry(): BelongsTo
    {
        return $this->belongsTo(DailyEntry::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** What the counter is still owed on this bill. */
    public function credit(): Money
    {
        $owed = $this->total->minus($this->received);

        return $owed->isNegative() ? Money::zero() : $owed;
    }

    /** Change handed back: anything taken beyond the bill. */
    public function change(): Money
    {
        $over = $this->received->minus($this->total);

        return $over->isNegative() ? Money::zero() : $over;
    }

    /** Sale value less what the goods cost. */
    public function margin(): Money
    {
        return $this->total->minus($this->cost);
    }

    /**
     * What this bill earned, once anything returned against it is taken
     * off: the sale kept, less what those goods cost. Gross profit — before
     * expenses — as the day and the reports count it.
     *
     * @return array{sale: Money, cost: Money, profit: Money, percent: ?float}
     */
    public function grossProfit(): array
    {
        $sale = $this->total->minus($this->returned());
        $cost = $this->cost->minus(Money::sum($this->returns->pluck('cost')));
        $profit = $sale->minus($cost);

        return [
            'sale' => $sale,
            'cost' => $cost,
            'profit' => $profit,
            'percent' => $sale->isPositive() ? round((float) $profit->toDecimal() / (float) $sale->toDecimal() * 100, 1) : null,
        ];
    }

    /**
     * The number on the paper: the day it was sold, then its place in that
     * day — 2910260001. Bills from before daily numbering keep the reference
     * they went out with.
     */
    public function reference(): string
    {
        if ($this->daily_no === null) {
            return 'B-' . str_pad((string) $this->bill_no, 5, '0', STR_PAD_LEFT);
        }

        return self::referenceFor($this->business_date, $this->daily_no);
    }

    public static function referenceFor(\Carbon\CarbonInterface $date, int $dailyNo): string
    {
        return $date->format('dmy') . str_pad((string) $dailyNo, 4, '0', STR_PAD_LEFT);
    }

    /** The next place in the day's count, starting from 1. */
    public static function nextDailyNo(Business $business, \Carbon\CarbonInterface $date, bool $lock = false): int
    {
        $query = self::forBusiness($business)->whereDate('business_date', $date->toDateString());

        if ($lock) {
            $query->lockForUpdate();
        }

        return (int) $query->max('daily_no') + 1;
    }
}

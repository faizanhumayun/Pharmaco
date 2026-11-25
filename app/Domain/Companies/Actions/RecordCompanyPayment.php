<?php

namespace App\Domain\Companies\Actions;

use App\Domain\Daily\DayWriter;
use App\Domain\Ledger\BalanceService;
use App\Exceptions\LedgerException;
use App\Models\Business;
use App\Models\Company;
use App\Models\DailyEntry;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Money paid to a company against what is already owed to it.
 *
 * Not an edit of any bill: the bills are true records of what was bought and
 * what was paid at the time. This is a separate event on the day the money
 * actually went, written onto that day's entry as a payment with no invoice
 * amount — which the day posts off the company's own ledger and out of cash,
 * exactly as a payment typed on the daily entry would be. One path, so the
 * ledger cannot be reached two ways.
 */
class RecordCompanyPayment
{
    public function __construct(
        private readonly DayWriter $day,
        private readonly BalanceService $balances,
    ) {}

    public function handle(
        Business $business,
        Company $company,
        Money $amount,
        User $by,
        ?string $reference = null,
        ?Carbon $date = null,
    ): DailyEntry {
        $date ??= $business->today();

        if ($company->business_id !== $business->id) {
            throw new LedgerException('This company belongs to another business.');
        }

        if (! $amount->isPositive()) {
            throw new LedgerException('Enter the amount paid.');
        }

        $pending = $company->account
            ? $this->balances->asAt($business, $company->account->code)
            : Money::zero();

        // Paying ahead is a different thing, entered with the delivery it is for.
        if ($amount->greaterThan($pending)) {
            throw new LedgerException(sprintf(
                'The pending balance of %s is Rs. %s. The payment cannot be more than that.',
                $company->name,
                $pending->isPositive() ? $pending->format() : '0.00',
            ));
        }

        $reference = trim((string) $reference);

        return $this->day->write($business, $date, function (array $data) use ($company, $amount, $reference) {
            $data['purchases'][] = [
                'company' => $company->name,
                'order_id' => null,
                'invoice_no' => $reference !== '' ? mb_substr($reference, 0, 60) : 'Payment',
                // Nothing bought: the whole of it comes off what is owed.
                'amount' => '0.00',
                'paid' => $amount->toDecimal(),
            ];

            return $data;
        }, $by);
    }
}

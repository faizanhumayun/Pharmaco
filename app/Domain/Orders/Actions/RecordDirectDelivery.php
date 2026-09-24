<?php

namespace App\Domain\Orders\Actions;

use App\Enums\OrderStatus;
use App\Exceptions\LedgerException;
use App\Models\Business;
use App\Models\Company;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A delivery that arrived without an order form.
 *
 * Most buying is ordered on paper first and checked off on arrival. Plenty is
 * not: the owner rings the company, and a fortnight later a van turns up. The
 * goods and the invoice are just as real, and refusing to record them until
 * somebody back-fills an order form is how stock stops matching the godown.
 *
 * It does not add a second way into stock. An order placed on the phone is
 * still an order — it simply was not written down first — so this writes that
 * order, marks it sent, and hands it to the same receiving that every other
 * delivery goes through. Everything downstream is unchanged: the receipt
 * lines, the invoice on the day's entry, the stock movements, the trail.
 */
class RecordDirectDelivery
{
    public function __construct(private readonly ReceiveOrder $receive) {}

    /**
     * @param  array<string, mixed>  $data  company_id, invoice_no, paid, notes
     *                                      and lines[] of company_product_id,
     *                                      packs, rate, note
     */
    public function handle(Business $business, array $data, User $by, ?Carbon $date = null): Order
    {
        $date ??= $business->today();

        $company = Company::forBusiness($business)->find($data['company_id'] ?? null);

        if ($company === null) {
            throw new LedgerException('A delivery has to come from a company you buy from.');
        }

        $lines = array_values(array_filter(
            $data['lines'] ?? [],
            fn ($row) => ($row['company_product_id'] ?? null) && (int) ($row['packs'] ?? 0) > 0
        ));

        if ($lines === []) {
            throw new LedgerException('Nothing was entered as arriving.');
        }

        if ($business->isDayClosed($date)) {
            throw new LedgerException(
                $date->format('D d M Y').' is closed. Reopen it on its closing page first.'
            );
        }

        return DB::transaction(function () use ($business, $company, $lines, $data, $by, $date) {
            /*
             * Sent, not draft: receiving refuses a draft, and rightly — but
             * this one genuinely was sent, over the phone. The note says so,
             * so a month later nobody wonders why an order form exists that
             * nobody remembers writing.
             */
            $order = Order::create([
                'business_id' => $business->id,
                'company_id' => $company->id,
                'reference' => Order::nextReference($business),
                'business_date' => $date->toDateString(),
                'status' => OrderStatus::Sent,
                'notes' => trim((string) ($data['notes'] ?? '')) ?: 'Ordered directly — no order form',
                'created_by' => $by->id,
                'sent_at' => now(),
            ]);

            // Everything arrived as an "extra", because nothing was asked for
            // on paper. That is exactly what an unordered line means already.
            return $this->receive->handle($order, [
                'extras' => $lines,
                'invoice_no' => $data['invoice_no'] ?? null,
                'paid' => $data['paid'] ?? '0',
            ], $by);
        });
    }
}

<?php

namespace App\Domain\Pharmacies\Actions;

use App\Domain\Ledger\ChartOfAccounts;
use App\Enums\AccountCode;
use App\Models\Business;
use App\Models\Pharmacy;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Adds a pharmacy and its own sub-ledger.
 *
 * The mirror of CreateCompany. The first pharmacy created moves postings off
 * the Market Receivables control account and onto children. From then on the
 * total receivable IS the sum of the pharmacy balances — structurally, not
 * because anything keeps them in step.
 */
class CreatePharmacy
{
    public function __construct(private readonly ChartOfAccounts $chart) {}

    /** @param array<string, mixed> $data */
    public function handle(Business $business, array $data, User $by): Pharmacy
    {
        return DB::transaction(function () use ($business, $data, $by) {
            // Moves any aggregate balance to Unallocated and closes the control
            // account to direct postings. Idempotent after the first pharmacy.
            $this->chart->openSubLedger($business, AccountCode::MarketReceivables, $by);

            $pharmacy = Pharmacy::create([
                'business_id' => $business->id,
                'name' => $data['name'],
                'code' => $data['code'] ?? null,
                'credit_days' => $data['credit_days'] ?? null,
                'contact' => $data['contact'] ?? null,
                'phone' => $data['phone'] ?? null,
                'area' => $data['area'] ?? null,
                'is_active' => true,
            ]);

            $suffix = str_pad((string) Pharmacy::forBusiness($business)->count(), 3, '0', STR_PAD_LEFT);

            $account = $this->chart->addSubAccount(
                $business,
                AccountCode::MarketReceivables,
                $suffix,
                $pharmacy->name,
                $pharmacy,
            );

            $pharmacy->forceFill(['account_id' => $account->id])->save();

            return $pharmacy->fresh('account');
        });
    }
}

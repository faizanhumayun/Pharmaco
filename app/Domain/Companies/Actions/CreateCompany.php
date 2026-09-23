<?php

namespace App\Domain\Companies\Actions;

use App\Domain\Ledger\ChartOfAccounts;
use App\Enums\AccountCode;
use App\Models\Business;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Adds a pharmaceutical company and its own sub-ledger.
 *
 * The first company created moves postings off the Company Payables control
 * account and onto children. From then on the total payable IS the sum of the
 * company balances — structurally, not because anything keeps them in step.
 */
class CreateCompany
{
    public function __construct(private readonly ChartOfAccounts $chart) {}

    /** @param array<string, mixed> $data */
    public function handle(Business $business, array $data, User $by): Company
    {
        return DB::transaction(function () use ($business, $data, $by) {
            // Moves any aggregate balance to Unallocated and closes the control
            // account to direct postings. Idempotent after the first company.
            $this->chart->openSubLedger($business, AccountCode::CompanyPayables, $by);

            $company = Company::create([
                'business_id' => $business->id,
                'name' => $data['name'],
                'code' => $data['code'] ?? null,
                'credit_days' => $data['credit_days'] ?? null,
                'contact' => $data['contact'] ?? null,
                'phone' => $data['phone'] ?? null,
                'is_active' => true,
            ]);

            $suffix = str_pad((string) Company::forBusiness($business)->count(), 3, '0', STR_PAD_LEFT);

            $account = $this->chart->addSubAccount(
                $business,
                AccountCode::CompanyPayables,
                $suffix,
                $company->name,
                $company,
            );

            $company->forceFill(['account_id' => $account->id])->save();

            return $company->fresh('account');
        });
    }
}

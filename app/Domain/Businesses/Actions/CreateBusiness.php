<?php

namespace App\Domain\Businesses\Actions;

use App\Domain\Ledger\ChartOfAccounts;
use App\Enums\BusinessStatus;
use App\Enums\BusinessType;
use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateBusiness
{
    public function __construct(private readonly ChartOfAccounts $chart) {}

    /** @param array<string, mixed> $data */
    public function handle(array $data, User $creator): Business
    {
        return DB::transaction(function () use ($data, $creator) {
            $business = Business::create([
                'name' => $data['name'],
                'slug' => $this->uniqueSlug($data['name']),
                'business_type' => BusinessType::from($data['business_type'] ?? BusinessType::Distributor->value),
                // A new tenant starts in setup: it cannot record activity until
                // its opening balance is finalized in Phase 4.
                'status' => BusinessStatus::Setup,
                'currency' => $data['currency'] ?? 'PKR',
                // How this business counts stock; the usual way for its kind
                // unless the App Owner says otherwise.
                'stock_unit' => $data['stock_unit']
                    ?? \App\Enums\StockUnit::defaultFor(BusinessType::from($data['business_type'] ?? BusinessType::Distributor->value))->value,
                'timezone' => $data['timezone'] ?? 'Asia/Karachi',
                'address' => $data['address'] ?? null,
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'ntn' => $data['ntn'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $creator->id,
            ]);

            // Seeded now so a business is never without the accounts its
            // ledger will post to.
            $this->chart->seed($business);

            return $business;
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'business';
        $slug = $base;
        $suffix = 2;

        while (Business::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}

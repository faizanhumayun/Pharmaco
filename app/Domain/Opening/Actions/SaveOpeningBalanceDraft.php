<?php

namespace App\Domain\Opening\Actions;

use App\Domain\Opening\OpeningBalanceCalculator;
use App\Domain\Opening\OpeningField;
use App\Enums\OpeningBalanceStatus;
use App\Exceptions\ImmutableRecordException;
use App\Models\Account;
use App\Models\Business;
use App\Models\OpeningBalance;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class SaveOpeningBalanceDraft
{
    public function __construct(private readonly OpeningBalanceCalculator $calculator) {}

    /** @param array<string, mixed> $data */
    public function handle(Business $business, array $data, User $by): OpeningBalance
    {
        $existing = OpeningBalance::forBusiness($business)->first();

        if ($existing && ! $existing->isEditable()) {
            throw ImmutableRecordException::forDocument('opening balance');
        }

        return DB::transaction(function () use ($business, $data, $by, $existing) {
            $opening = $existing ?? new OpeningBalance(['business_id' => $business->id, 'created_by' => $by->id]);

            $opening->fill([
                'business_id' => $business->id,
                'opening_date' => $data['opening_date'],
                'status' => OpeningBalanceStatus::Draft,
                'notes' => $data['notes'] ?? null,
                'created_by' => $opening->created_by ?? $by->id,
            ])->save();

            $accounts = Account::forBusiness($business)->pluck('id', 'code');

            foreach (OpeningField::all() as $field) {
                $opening->lines()->updateOrCreate(
                    ['account_id' => $accounts[$field->key()]],
                    ['amount' => Money::of($data[$field->key()] ?? null)->toDecimal()]
                );
            }

            // Kept on the draft so the review screen and the listing agree
            // without recomputing; recomputed from the lines on every save.
            $position = $this->calculator->fromRecord($opening->fresh('lines.account'));

            $opening->forceFill([
                'total_assets' => $position->assets->toDecimal(),
                'total_liabilities' => $position->liabilities->toDecimal(),
                'net_position' => $position->netPosition()->toDecimal(),
                'balancing_figure' => $position->balancingFigure()->toDecimal(),
            ])->save();

            return $opening->fresh('lines.account');
        });
    }
}

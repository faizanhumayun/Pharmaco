<?php

namespace App\Domain\Businesses\Actions;

use App\Domain\Ledger\ChartOfAccounts;
use App\Enums\AccountCode;
use App\Models\Business;
use App\Models\BusinessPaymentMethod;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Says which ways of being paid a business uses.
 *
 * Switching one on gives it an account of its own under Bank the first time,
 * so what arrives by JazzCash is a balance that can be checked against the
 * JazzCash statement. Switching one off only hides it from the till — its
 * account and everything posted to it stay exactly where they are.
 */
class SetPaymentMethods
{
    public function __construct(private readonly ChartOfAccounts $chart) {}

    /**
     * @param  array<int, int>  $chosen  payment method ids to have switched on
     * @param  array<int, string|null>  $titles  account title or number, by payment method id
     */
    public function handle(Business $business, array $chosen, array $titles, User $by): void
    {
        DB::transaction(function () use ($business, $chosen, $titles, $by) {
            $chosen = array_map('intval', $chosen);
            $existing = BusinessPaymentMethod::forBusiness($business)->get()->keyBy('payment_method_id');
            $changed = [];

            foreach (PaymentMethod::query()->orderBy('sort_order')->get() as $method) {
                $wanted = in_array($method->id, $chosen, true) && $method->is_active;
                $mine = $existing->get($method->id);
                $title = trim((string) ($titles[$method->id] ?? '')) ?: null;

                if ($mine === null && ! $wanted) {
                    continue;
                }

                if ($mine === null) {
                    $mine = BusinessPaymentMethod::create([
                        'business_id' => $business->id,
                        'payment_method_id' => $method->id,
                        'title' => $title,
                        'is_active' => true,
                    ]);

                    // Bank itself never takes a posting; each method's own
                    // account under it does.
                    $account = $this->chart->addSubAccount(
                        $business,
                        AccountCode::Bank,
                        str_pad((string) $method->id, 3, '0', STR_PAD_LEFT),
                        $method->name,
                        $mine,
                    );

                    $mine->forceFill(['account_id' => $account->id])->save();
                    $changed[] = $method->name.' switched on';

                    continue;
                }

                if ($mine->is_active !== $wanted) {
                    $changed[] = $method->name.($wanted ? ' switched on' : ' switched off');
                }

                $mine->forceFill(['is_active' => $wanted, 'title' => $wanted ? $title : $mine->title])->save();
            }

            if ($changed !== []) {
                activity()
                    ->performedOn($business)
                    ->causedBy($by)
                    ->withProperties(['changes' => $changed])
                    ->event('business.payment_methods')
                    ->log('Payment methods changed');
            }
        });
    }
}

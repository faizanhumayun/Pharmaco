<?php

namespace App\Providers;

use App\Enums\BusinessType;
use App\Models\Business;
use App\Models\DailyClosing;
use App\Models\DailyEntry;
use App\Models\OpeningBalance;
use App\Models\User;
use App\Policies\BusinessPolicy;
use App\Policies\DailyClosingPolicy;
use App\Policies\DailyEntryPolicy;
use App\Policies\OpeningBalancePolicy;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        Business::class => BusinessPolicy::class,
        OpeningBalance::class => OpeningBalancePolicy::class,
        DailyEntry::class => DailyEntryPolicy::class,
        DailyClosing::class => DailyClosingPolicy::class,
        User::class => UserPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();

        /*
         * Platform admins get platform abilities, and nothing more.
         *
         * This is deliberately NOT a blanket bypass. In a financial system a
         * super-admin override defeats the audit trail, so abilities that would
         * rewrite history — editing a posted transaction, editing a finalized
         * opening balance — are denied to everyone, including here.
         */
        Gate::before(function (User $user, string $ability) {
            if (! $user->isPlatformAdmin()) {
                return null;
            }

            return in_array($ability, self::PLATFORM_ABILITIES, true) ? true : null;
        });

        // Kept as the one place that says which kinds of business may be set up.
        Gate::define('use-business-type', fn (User $user, BusinessType $type) => $type->isAvailable());
    }

    /** Abilities the platform admin holds by virtue of operating the platform. */
    private const PLATFORM_ABILITIES = [
        'manage-platform',
        'view-all-businesses',
        'manage-users',
        'view-audit-log',
    ];
}

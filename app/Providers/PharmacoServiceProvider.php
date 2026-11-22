<?php

namespace App\Providers;

use App\Models\Transaction;
use App\Support\CurrentBusiness as CurrentBusinessResolver;
use Spatie\Activitylog\Models\Activity;
use App\Observers\TransactionObserver;
use App\Support\CurrentBusiness;
use Illuminate\Support\ServiceProvider;

class PharmacoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One business context per request, shared by the global scope,
        // the middleware and anything that needs to know where it is.
        $this->app->singleton(CurrentBusiness::class);
    }

    public function boot(): void
    {
        // A request from a browser that has stepped into a shop's copy, or on
        // a shop's own address, is answered from that shop's read-only copy. Decided here, before anything reads the database.
        if (! $this->app->runningInConsole()) {
            \App\Support\MirrorHost::apply(request()->getHost(), request()->cookies->get(\App\Support\MirrorHost::COOKIE));
        }

        Transaction::observe(TransactionObserver::class);

        /*
         * Every audit record carries the tenant and the caller's IP, so the
         * trail can be read per business and a denied attempt can be traced.
         */
        Activity::creating(function (Activity $activity) {
            $subject = $activity->subject;

            $activity->business_id ??= $subject->business_id
                ?? app(CurrentBusinessResolver::class)->id();

            $activity->ip_address ??= request()->ip();

            $activity->reason ??= data_get($activity->properties, 'reason')
                ?? data_get($activity->properties, 'correction_reason');
        });
    }
}

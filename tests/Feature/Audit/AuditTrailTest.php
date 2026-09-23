<?php

use App\Enums\BusinessRole;
use App\Models\DailyEntry;
use Spatie\Activitylog\Models\Activity;

it('records who finalized an opening balance and the values they confirmed', function () {
    [$business] = businessWithPostedDay();

    $activity = Activity::where('event', 'opening_balance.finalized')->firstOrFail();

    // "User X updated record 42" is not an audit trail; the values are.
    expect($activity->properties)->toHaveKey('lines')
        ->and($activity->properties['net_position'])->toBe('-1699907.00')
        ->and($activity->properties['confirmation'])->toContain('actual opening financial position')
        ->and($activity->causer)->not->toBeNull()
        ->and($activity->business_id)->toBe($business->id);
});

it('records the figures posted for a day', function () {
    [$business] = businessWithPostedDay();

    $activity = Activity::where('event', 'daily_entry.posted')->firstOrFail();

    expect($activity->properties['figures'])->toHaveKey('sale_credit')
        ->and($activity->business_id)->toBe($business->id);
});

it('carries the stated reason onto the audit record', function () {
    [$business, $owner] = businessWithPostedDay();
    $entry = DailyEntry::forBusiness($business)->firstOrFail();

    $this->actingAs($owner)->post(route('businesses.daily.reverse', [$business, $entry]), [
        'reason' => 'Sales figure taken from the wrong POS report.',
    ]);

    $activity = Activity::where('event', 'daily_entry.reversed')->firstOrFail();

    expect($activity->reason)->toBe('Sales figure taken from the wrong POS report.');
});

it('records the closing and the reopen with its reason', function () {
    [$business, $owner] = businessWithPostedDay();
    $date = $business->today()->toDateString();

    $this->actingAs($owner)->post(route('businesses.closing.reconcile', [$business, $date]), ['counted_cash' => '15093']);
    $this->actingAs($owner)->post(route('businesses.closing.finalize', [$business, $date]));
    $this->actingAs($owner)->post(route('businesses.closing.reopen', [$business, $date]), [
        'reason' => 'Company invoice arrived late.',
    ]);

    expect(Activity::where('event', 'closing.finalized')->exists())->toBeTrue()
        ->and(Activity::where('event', 'closing.reopened')->firstOrFail()->reason)
            ->toBe('Company invoice arrived late.');
});

it('offers the audit trail to owners and hides it from operators', function () {
    [$business, $owner] = businessWithPostedDay();
    $operator = App\Models\User::factory()->create();
    $business->members()->attach($operator->id, ['role' => BusinessRole::Operator->value, 'is_active' => true]);

    $this->actingAs($owner)->get(route('businesses.audit', $business))->assertOk();
    $this->actingAs($operator)->get(route('businesses.audit', $business))->assertForbidden();
});

it('exposes no route that edits or deletes an audit record', function () {
    $names = collect(Route::getRoutes())->map(fn ($r) => $r->getName())->filter();

    expect($names->filter(fn ($n) => str_contains($n, 'audit') && $n !== 'businesses.audit'))
        ->toBeEmpty();
});

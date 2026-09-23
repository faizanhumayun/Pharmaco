<?php

use App\Models\Company;
use App\Models\DailyEntry;

/*
| Every screen is rendered at least once, so a Blade error cannot reach the
| browser without a test noticing first.
*/

it('renders every business screen', function () {
    [$business, $owner] = businessWithPostedDay();
    $entry = DailyEntry::forBusiness($business)->firstOrFail();

    $this->actingAs($owner)->post(route('businesses.companies.store', $business), ['name' => 'Getz Pharma']);
    $company = Company::forBusiness($business)->firstOrFail();

    foreach ([
        route('businesses.show', $business),
        route('businesses.daily.index', $business),
        route('businesses.daily.show', [$business, $entry]),
        route('businesses.closing.index', $business),
        route('businesses.closing.show', $business),
        route('businesses.stock.index', $business),
        route('businesses.companies.index', $business),
        route('businesses.companies.show', [$business, $company]),
        route('businesses.audit', $business),
    ] as $url) {
        $this->actingAs($owner)->get($url)->assertOk();
    }
});

it('renders the daily entry form on a day with no entry yet', function () {
    // The worked-day business already has today posted, and asking to enter it
    // again correctly redirects to the posted day instead.
    [$business, $owner] = tradingBusiness();

    $this->actingAs($owner)->get(route('businesses.daily.create', $business))->assertOk();
});

it('sends you to the posted day rather than a second entry form', function () {
    [$business, $owner] = businessWithPostedDay();

    $this->actingAs($owner)
        ->get(route('businesses.daily.create', $business))
        ->assertRedirect();
});

it('keeps every business screen out of reach of an outsider', function () {
    [$business] = businessWithPostedDay();
    [, $outsider] = businessWithMember();

    foreach ([
        route('businesses.show', $business),
        route('businesses.daily.index', $business),
        route('businesses.closing.index', $business),
        route('businesses.stock.index', $business),
        route('businesses.companies.index', $business),
        route('businesses.audit', $business),
    ] as $url) {
        $this->actingAs($outsider)->get($url)->assertForbidden();
    }
});

it('renders the opening balance wizard end to end', function () {
    $business = setupBusiness();
    $admin = platformAdmin();

    $this->actingAs($admin)->get(route('admin.businesses.opening.edit', $business))->assertOk();

    $this->actingAs($admin)->put(route('admin.businesses.opening.update', $business), array_replace([
        'opening_date' => '2026-08-31',
        '1200' => '1000000', '1000' => '93', '1100' => '500000', '2000' => '3200000',
    ], []));

    $this->actingAs($admin)->get(route('admin.businesses.opening.review', $business))
        ->assertOk()
        ->assertSee('-1,699,907.00')
        ->assertSee('This opening position is negative');

    $this->actingAs($admin)->post(route('admin.businesses.opening.finalize', $business), [
        'confirmed' => '1', 'notes' => 'Trading on supplier credit.',
    ]);

    $this->actingAs($admin)->get(route('admin.businesses.opening.show', $business))
        ->assertOk()
        ->assertSee('Opening balance finalized');
});

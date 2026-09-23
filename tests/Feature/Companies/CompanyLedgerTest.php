<?php

use App\Domain\Ledger\BalanceService;
use App\Enums\AccountCode;
use App\Models\Company;
use App\Models\DailyEntry;

it('moves the existing total into Unallocated by a visible transfer', function () {
    [$business, $owner] = businessWithPostedDay();
    $balances = app(BalanceService::class);

    $before = $balances->asAt($business, AccountCode::CompanyPayables);

    $this->actingAs($owner)->post(route('businesses.companies.store', $business), [
        'name' => 'Getz Pharma',
    ])->assertRedirect();

    // Ledger entries are append-only, so history is not rewritten — the balance
    // is transferred, and the total is unchanged.
    expect($balances->asAt($business, AccountCode::CompanyPayables)->equals($before))->toBeTrue()
        ->and($balances->asAt($business, '2000-000')->equals($before))->toBeTrue()
        ->and($balances->directBalance($business, AccountCode::CompanyPayables)->isZero())->toBeTrue();
});

it('keeps the control account equal to the sum of its children', function () {
    [$business, $owner] = businessWithPostedDay();

    foreach (['Getz Pharma', 'Abbott', 'GSK'] as $name) {
        $this->actingAs($owner)->post(route('businesses.companies.store', $business), ['name' => $name]);
    }

    $balances = app(BalanceService::class);

    expect($balances->controlReconciles($business, AccountCode::CompanyPayables))->toBeTrue()
        ->and($balances->trialBalance($business)['balanced'])->toBeTrue();
});

it('stops the control account taking direct postings once companies exist', function () {
    [$business, $owner] = businessWithPostedDay();

    $this->actingAs($owner)->post(route('businesses.companies.store', $business), ['name' => 'Getz Pharma']);

    expect(App\Models\Account::forBusiness($business)
        ->code(AccountCode::CompanyPayables)->first()->is_postable)->toBeFalse();
});

it('routes later summary entries to Unallocated, because a day total names no company', function () {
    [$business, $owner] = businessWithPostedDay();

    $this->actingAs($owner)->post(route('businesses.companies.store', $business), ['name' => 'Getz Pharma']);

    // A second trading day, entered at summary level as usual.
    $this->actingAs($owner)->post(route('businesses.daily.store', $business), workedDayInput([
        'business_date' => '2026-09-01',
        'purchase_total' => '0', 'purchase_paid' => '0', 'purchase_discount' => '0',
        'sale_cash' => '0', 'sale_credit' => '0', 'gross_profit' => '0',
        'collection_cash' => '0', 'company_payment_cash' => '10000', 'expenses_cash' => '0',
    ]));

    $entry = DailyEntry::forBusiness($business)->firstOrFail();
    $this->actingAs($owner)->post(route('businesses.daily.post', [$business, $entry]));

    $balances = app(BalanceService::class);

    expect($balances->controlReconciles($business, AccountCode::CompanyPayables))->toBeTrue()
        ->and($balances->trialBalance($business)['balanced'])->toBeTrue();
});

it('gives each company its own numbered account', function () {
    [$business, $owner] = businessWithPostedDay();

    $this->actingAs($owner)->post(route('businesses.companies.store', $business), ['name' => 'Getz Pharma']);
    $this->actingAs($owner)->post(route('businesses.companies.store', $business), ['name' => 'Abbott']);

    $codes = Company::forBusiness($business)->with('account')->get()->pluck('account.code')->sort()->values();

    expect($codes->all())->toBe(['2000-001', '2000-002']);
});

it('renders a company statement with a running balance', function () {
    [$business, $owner] = businessWithPostedDay();

    $this->actingAs($owner)->post(route('businesses.companies.store', $business), ['name' => 'Getz Pharma']);

    $this->actingAs($owner)
        ->get(route('businesses.companies.index', $business))
        ->assertOk()
        // The reconciliation badge, in the page's plain wording.
        ->assertSee('Adds up across companies ✓', escape: false);
});

it('ends with the ledger still verifying clean', function () {
    [$business, $owner] = businessWithPostedDay();

    $this->actingAs($owner)->post(route('businesses.companies.store', $business), ['name' => 'Getz Pharma']);

    $this->artisan('ledger:verify', ['--business' => $business->slug])->assertSuccessful();
});

<?php

use App\Domain\Ledger\ChartOfAccounts;
use App\Enums\AccountCode;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Business;

it('seeds the full chart when a business is created', function () {
    $this->actingAs(platformAdmin())->post(route('admin.businesses.store'), [
        'name' => 'ABC Pharma Distribution',
        'business_type' => 'distributor',
        'currency' => 'PKR',
        'timezone' => 'Asia/Karachi',
    ])->assertRedirect();

    $business = Business::where('name', 'ABC Pharma Distribution')->firstOrFail();

    // A business is never without the accounts its ledger will post to.
    expect(Account::forBusiness($business)->count())->toBe(count(AccountCode::cases()));
});

it('gives each business its own accounts with their own ids', function () {
    [$first] = ledgerBusiness();
    [$second] = ledgerBusiness();

    $firstCash = Account::forBusiness($first)->code(AccountCode::Cash)->firstOrFail();
    $secondCash = Account::forBusiness($second)->code(AccountCode::Cash)->firstOrFail();

    // Codes are shared; ids are not. Anything that caches an account id across
    // businesses would post into the wrong tenant's ledger.
    expect($firstCash->code)->toBe($secondCash->code)
        ->and($firstCash->id)->not->toBe($secondCash->id);
});

it('assigns the right type and normal balance to every account', function () {
    [$business] = ledgerBusiness();

    foreach (AccountCode::cases() as $code) {
        $account = Account::forBusiness($business)->code($code)->firstOrFail();

        expect($account->type)->toBe($code->type());
    }

    expect(AccountType::Asset->increasesOnDebit())->toBeTrue()
        ->and(AccountType::Liability->increasesOnDebit())->toBeFalse()
        ->and(AccountType::Income->increasesOnDebit())->toBeFalse()
        ->and(AccountType::Expense->increasesOnDebit())->toBeTrue();
});

it('seeds the bank account but leaves it unusable', function () {
    [$business] = ledgerBusiness();

    $bank = Account::forBusiness($business)->code(AccountCode::Bank)->firstOrFail();

    // Seeded so enabling a bank later is a settings change, not a migration.
    expect($bank->exists)->toBeTrue()
        ->and($bank->is_postable)->toBeFalse();
});

it('marks receivables and payables as control accounts', function () {
    [$business] = ledgerBusiness();

    foreach ([AccountCode::MarketReceivables, AccountCode::CompanyPayables] as $code) {
        expect(Account::forBusiness($business)->code($code)->firstOrFail()->is_control)->toBeTrue();
    }
});

it('stops a control account accepting postings the moment it gains a child', function () {
    [$business] = ledgerBusiness();

    $parent = Account::forBusiness($business)->code(AccountCode::CompanyPayables)->firstOrFail();
    expect($parent->is_postable)->toBeTrue();

    $child = app(ChartOfAccounts::class)
        ->addSubAccount($business, AccountCode::CompanyPayables, '001', 'Getz Pharma');

    // The total must be the sum of the parts by construction, so the parent
    // stops accepting direct postings at the same moment it gains parts.
    expect($parent->fresh()->is_postable)->toBeFalse()
        ->and($child->parent_id)->toBe($parent->id)
        ->and($child->type)->toBe($parent->type)
        ->and($child->code)->toBe('2000-001');
});

it('is idempotent, so a later phase can add a code and backfill', function () {
    [$business] = ledgerBusiness();

    app(ChartOfAccounts::class)->seed($business);
    app(ChartOfAccounts::class)->seed($business);

    expect(Account::forBusiness($business)->count())->toBe(count(AccountCode::cases()));
});

it('marks seeded accounts as system accounts users cannot remove', function () {
    [$business] = ledgerBusiness();

    expect(Account::forBusiness($business)->where('is_system', false)->count())->toBe(0);
});

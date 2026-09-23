<?php

use App\Domain\Ledger\PostingLine;
use App\Enums\AccountCode;
use App\Enums\TransactionType;
use App\Models\Account;
use Illuminate\Support\Facades\DB;

it('passes on a healthy ledger', function () {
    [$business, $user] = ledgerBusiness();

    postEntry($business, $user, [
        PostingLine::debit(AccountCode::Cash, '5000.00'),
        PostingLine::credit(AccountCode::Sales, '5000.00'),
    ], TransactionType::SaleCash, reason: null);

    $this->artisan('ledger:verify', ['--business' => $business->slug])
        ->assertSuccessful();
});

it('catches an unbalanced transaction inserted behind the service', function () {
    [$business, $user] = ledgerBusiness();

    $transaction = postEntry($business, $user, [
        PostingLine::debit(AccountCode::Cash, '5000.00'),
        PostingLine::credit(AccountCode::Sales, '5000.00'),
    ], TransactionType::SaleCash, reason: null);

    // Corrupt it the way only a raw query could, and prove the check notices.
    DB::table('ledger_entries')->insert([
        'business_id' => $business->id,
        'transaction_id' => $transaction->id,
        'account_id' => Account::forBusiness($business)->code(AccountCode::Cash)->value('id'),
        'business_date' => $business->today()->toDateString(),
        'debit' => '1000.00',
        'credit' => '0.00',
        'created_at' => now(),
    ]);

    $this->artisan('ledger:verify', ['--business' => $business->slug])
        ->assertFailed();
});

it('catches a postable account that has gained sub-accounts', function () {
    [$business] = ledgerBusiness();

    $parent = Account::forBusiness($business)->code(AccountCode::CompanyPayables)->firstOrFail();

    Account::create([
        'business_id' => $business->id,
        'code' => '2000-999',
        'name' => 'Sneaky child',
        'type' => $parent->type,
        'parent_id' => $parent->id,
    ]);

    // The parent was left postable, so the total could drift from the parts.
    $this->artisan('ledger:verify', ['--business' => $business->slug])
        ->assertFailed();
});

it('reports success when there is nothing to check', function () {
    $this->artisan('ledger:verify')->assertSuccessful();
});

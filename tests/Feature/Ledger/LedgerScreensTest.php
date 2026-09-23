<?php

use App\Domain\Ledger\PostingLine;
use App\Enums\AccountCode;
use App\Enums\TransactionType;
use App\Models\Business;

it('renders the chart of accounts with derived balances', function () {
    [$business, $user] = ledgerBusiness();

    postEntry($business, $user, [
        PostingLine::debit(AccountCode::Cash, '15093.00'),
        PostingLine::credit(AccountCode::Sales, '15093.00'),
    ], TransactionType::SaleCash, reason: null);

    $this->actingAs($user)
        ->get(route('businesses.ledger', $business))
        ->assertOk()
        ->assertSee('15,093.00')
        // The balanced-books badge, in the page's plain wording.
        ->assertSee('Books balance ✓', escape: false)
        ->assertSee('Cash in Hand');
});

it('renders an account statement with a running balance', function () {
    [$business, $user] = ledgerBusiness();

    postEntry($business, $user, [
        PostingLine::debit(AccountCode::Cash, '1000.00'),
        PostingLine::credit(AccountCode::Sales, '1000.00'),
    ], TransactionType::SaleCash, reason: null);

    postEntry($business, $user, [
        PostingLine::debit(AccountCode::OperatingExpenses, '250.00'),
        PostingLine::credit(AccountCode::Cash, '250.00'),
    ], TransactionType::Expense, reason: null);

    $this->actingAs($user)
        ->get(route('businesses.ledger.account', [$business, AccountCode::Cash->value]))
        ->assertOk()
        ->assertSee('1,000.00')
        ->assertSee('750.00');
});

it('keeps the ledger screens out of reach of another business', function () {
    [$business] = ledgerBusiness();
    [, $outsider] = businessWithMember();

    $this->actingAs($outsider)
        ->get(route('businesses.ledger', $business))
        ->assertForbidden();
});

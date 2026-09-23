<?php

use App\Domain\Ledger\PostingLine;
use App\Enums\AccountCode;
use App\Enums\BusinessRole;
use App\Enums\TransactionType;
use App\Exceptions\ImmutableRecordException;
use App\Models\LedgerEntry;
use App\Models\Transaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Immutability
|--------------------------------------------------------------------------
|
| The whole audit trail rests on these. A posted record cannot be edited by
| anyone — not an operator, not the owner, not the App Owner. The only
| mutation the system offers is a new transaction.
|
*/

function postedTransaction(): array
{
    [$business, $user] = ledgerBusiness();

    $transaction = postEntry($business, $user, [
        PostingLine::debit(AccountCode::Cash, '5000.00'),
        PostingLine::credit(AccountCode::Sales, '5000.00'),
    ], TransactionType::SaleCash, reason: null);

    return [$business, $user, $transaction];
}

it('refuses to change a posted transaction', function () {
    [, , $transaction] = postedTransaction();

    expect(fn () => $transaction->update(['amount' => '999.00']))
        ->toThrow(ImmutableRecordException::class);

    expect(fn () => $transaction->update(['narration' => 'rewritten history']))
        ->toThrow(ImmutableRecordException::class);

    expect(fn () => $transaction->update(['business_date' => '2020-01-01']))
        ->toThrow(ImmutableRecordException::class);
});

it('refuses to delete a posted transaction', function () {
    [, , $transaction] = postedTransaction();

    expect(fn () => $transaction->delete())->toThrow(ImmutableRecordException::class);

    expect(Transaction::acrossAllBusinesses()->count())->toBe(1);
});

it('refuses to change or delete a ledger entry, ever', function () {
    [, , $transaction] = postedTransaction();
    $line = $transaction->lines->first();

    expect(fn () => $line->update(['debit' => '1.00']))
        ->toThrow(ImmutableRecordException::class);

    expect(fn () => $line->delete())
        ->toThrow(ImmutableRecordException::class);
});

it('denies the App Owner the same edit that it denies everyone else', function () {
    [, , $transaction] = postedTransaction();

    // A super-admin override in a financial system destroys the audit trail,
    // and it does get used. There is no role that can do this.
    $this->actingAs(platformAdmin());

    expect(fn () => $transaction->update(['amount' => '1.00']))
        ->toThrow(ImmutableRecordException::class);
});

it('still allows the reversal linkage, which is the one permitted write', function () {
    [$business, $user, $transaction] = postedTransaction();

    $reversal = app(App\Domain\Ledger\LedgerPoster::class)
        ->reverse($transaction, 'entered twice', $user);

    expect($transaction->fresh()->reversed_by_id)->toBe($reversal->id);
});

it('lets the database refuse a one-sided rule violation even if the service is bypassed', function () {
    [$business, , $transaction] = postedTransaction();

    // The posting service can be circumvented by a raw query; the CHECK
    // constraint cannot. Both layers exist for that reason.
    expect(fn () => DB::table('ledger_entries')->insert([
        'business_id' => $business->id,
        'transaction_id' => $transaction->id,
        'account_id' => $transaction->lines->first()->account_id,
        'business_date' => $business->today()->toDateString(),
        'debit' => '10.00',
        'credit' => '10.00',
        'created_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('lets the database refuse a negative amount', function () {
    [$business, , $transaction] = postedTransaction();

    expect(fn () => DB::table('ledger_entries')->insert([
        'business_id' => $business->id,
        'transaction_id' => $transaction->id,
        'account_id' => $transaction->lines->first()->account_id,
        'business_date' => $business->today()->toDateString(),
        'debit' => '-10.00',
        'credit' => '0.00',
        'created_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('lets the database refuse an empty line', function () {
    [$business, , $transaction] = postedTransaction();

    expect(fn () => DB::table('ledger_entries')->insert([
        'business_id' => $business->id,
        'transaction_id' => $transaction->id,
        'account_id' => $transaction->lines->first()->account_id,
        'business_date' => $business->today()->toDateString(),
        'debit' => '0.00',
        'credit' => '0.00',
        'created_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('has no soft deletes on any financial table', function () {
    foreach ([Transaction::class, LedgerEntry::class, App\Models\Account::class] as $model) {
        expect(method_exists(new $model, 'trashed'))->toBeFalse();
    }
});

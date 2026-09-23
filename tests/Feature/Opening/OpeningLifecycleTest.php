<?php

use App\Domain\Ledger\BalanceService;
use App\Domain\Ledger\PostingLine;
use App\Enums\AccountCode;
use App\Enums\OpeningBalanceStatus;
use App\Enums\TransactionType;
use App\Exceptions\ImmutableRecordException;
use App\Models\OpeningBalance;
use App\Models\Transaction;

function finalizedOpening(): array
{
    $business = setupBusiness();
    $admin = platformAdmin();

    test()->actingAs($admin)->put(route('admin.businesses.opening.update', $business), array_replace([
        'opening_date' => '2026-09-01',
        '1200' => '1000000', '1000' => '93', '1100' => '500000', '2000' => '3200000',
    ], []));

    test()->actingAs($admin)->post(route('admin.businesses.opening.finalize', $business), [
        'confirmed' => '1',
        'notes' => 'Trading on supplier credit. Vehicle not yet valued.',
    ]);

    return [$business->fresh(), $business->openingBalance()->first(), $admin];
}

it('refuses to edit a finalized opening balance, even for the App Owner', function () {
    [, $opening] = finalizedOpening();

    expect(fn () => $opening->update(['opening_date' => '2020-01-01']))
        ->toThrow(ImmutableRecordException::class);
});

it('refuses to delete a finalized opening balance', function () {
    [, $opening] = finalizedOpening();

    expect(fn () => $opening->delete())->toThrow(ImmutableRecordException::class);
});

it('offers no route to save over a finalized opening balance', function () {
    [$business, $opening, $admin] = finalizedOpening();

    $this->actingAs($admin)
        ->put(route('admin.businesses.opening.update', $business), [
            'opening_date' => '2026-09-01', '1200' => '9999999',
            '1000' => '0', '1100' => '0', '2000' => '0',
        ])
        ->assertForbidden();

    expect(app(BalanceService::class)->asAt($business, AccountCode::Stock)->toDecimal())
        ->toBe('1000000.00');
});

it('offers no way back to the edit screen once finalized', function () {
    [$business, , $admin] = finalizedOpening();

    $this->actingAs($admin)
        ->get(route('admin.businesses.opening.edit', $business))
        ->assertForbidden();
});

it('locks the opening balance the moment the business trades', function () {
    [$business, $opening, $admin] = finalizedOpening();

    expect($opening->status)->toBe(OpeningBalanceStatus::Finalized);

    postEntry($business, $admin, [
        PostingLine::debit(AccountCode::Cash, '5000.00'),
        PostingLine::credit(AccountCode::Sales, '5000.00'),
    ], TransactionType::SaleCash, date: '2026-09-02', reason: null);

    expect($business->openingBalance()->first()->status)->toBe(OpeningBalanceStatus::Locked);
});

it('still allows notes to be added after finalization, so a position can be explained later', function () {
    [, $opening] = finalizedOpening();

    $opening->update(['notes' => 'Vehicle valued at 350,000 — correction to follow.']);

    expect($opening->fresh()->notes)->toContain('350,000');
});

it('corrects a finalized opening balance with a dated adjustment, not an edit', function () {
    [$business, $opening, $admin] = finalizedOpening();
    $balances = app(BalanceService::class);

    $this->actingAs($admin)->post(route('admin.businesses.opening.correct', $business), [
        'account' => AccountCode::Stock->value,
        'amount' => '200000',
        'reason' => 'Opening stock understated — Godown 2 not included in the count of 01-09-2026.',
    ])->assertRedirect();

    $adjustment = Transaction::forBusiness($business)
        ->where('type', TransactionType::Adjustment)->firstOrFail();

    expect($balances->asAt($business, AccountCode::Stock)->toDecimal())->toBe('1200000.00')
        // The correction lands on the earliest open day after the opening —
        // nothing is closed here, so the day after it — carrying the date it
        // belongs to, and the original opening entry is untouched.
        ->and($adjustment->business_date->toDateString())
            ->toBe($business->opening_date->copy()->addDay()->toDateString())
        ->and($adjustment->original_business_date->toDateString())->toBe('2026-09-01')
        ->and($adjustment->correction_reason)->toContain('Godown 2')
        ->and($opening->fresh()->transaction->lines()->count())->toBe(5)
        ->and($balances->trialBalance($business)['balanced'])->toBeTrue();
});

it('reduces a figure when the correction is negative', function () {
    [$business, , $admin] = finalizedOpening();

    $this->actingAs($admin)->post(route('admin.businesses.opening.correct', $business), [
        'account' => AccountCode::CompanyPayables->value,
        'amount' => '-200000',
        'reason' => 'Company statement showed 3,000,000 not 3,200,000.',
    ])->assertRedirect();

    expect(app(BalanceService::class)->asAt($business, AccountCode::CompanyPayables)->toDecimal())
        ->toBe('3000000.00');
});

it('demands a reason for a correction', function () {
    [$business, , $admin] = finalizedOpening();

    $this->actingAs($admin)->post(route('admin.businesses.opening.correct', $business), [
        'account' => AccountCode::Stock->value, 'amount' => '200000', 'reason' => '',
    ])->assertSessionHasErrors('reason');

    expect(Transaction::forBusiness($business)->where('type', TransactionType::Adjustment)->count())->toBe(0);
});

it('refuses a correction of zero', function () {
    [$business, , $admin] = finalizedOpening();

    $this->actingAs($admin)->post(route('admin.businesses.opening.correct', $business), [
        'account' => AccountCode::Stock->value, 'amount' => '0', 'reason' => 'No change at all.',
    ])->assertSessionHasErrors('amount');
});

it('cannot correct a draft, which should simply be edited', function () {
    $business = setupBusiness();
    $admin = platformAdmin();

    $this->actingAs($admin)->put(route('admin.businesses.opening.update', $business), array_replace([
        'opening_date' => '2026-09-01', '1200' => '1000', '1000' => '0', '1100' => '0', '2000' => '0',
    ], []));

    $this->actingAs($admin)->post(route('admin.businesses.opening.correct', $business), [
        'account' => AccountCode::Stock->value, 'amount' => '500', 'reason' => 'Trying to correct a draft.',
    ])->assertForbidden();
});

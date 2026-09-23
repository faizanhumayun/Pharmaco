<?php

use App\Support\Money;

it('never loses precision the way floats do', function () {
    // 0.1 + 0.2 === 0.30000000000000004 in float arithmetic.
    expect(Money::of('0.10')->plus('0.20')->toDecimal())->toBe('0.30');
});

it('stays exact when a value is added up many times', function () {
    $total = Money::zero();

    for ($i = 0; $i < 1000; $i++) {
        $total = $total->plus('0.01');
    }

    expect($total->toDecimal())->toBe('10.00');
});

it('handles the specification\'s own opening figures', function () {
    $assets = Money::sum([Money::of('1000000.00'), Money::of('93.00'), Money::of('500000.00')]);
    $liabilities = Money::of('3200000.00');

    expect($assets->toDecimal())->toBe('1500093.00')
        ->and($assets->minus($liabilities)->toDecimal())->toBe('-1699907.00')
        ->and($assets->minus($liabilities)->isNegative())->toBeTrue();
});

it('parses grouped input from a form field', function () {
    expect(Money::of('1,699,907.00')->toDecimal())->toBe('1699907.00');
});

it('rejects values that are not money', function () {
    expect(fn () => Money::of('twelve'))->toThrow(InvalidArgumentException::class);
    expect(fn () => Money::of('12.3.4'))->toThrow(InvalidArgumentException::class);
});

it('treats null and empty as zero', function () {
    expect(Money::of(null)->isZero())->toBeTrue()
        ->and(Money::of('')->isZero())->toBeTrue();
});

it('truncates to two decimal places rather than carrying hidden precision', function () {
    expect(Money::of('10.999')->toDecimal())->toBe('10.99');
});

it('compares without floating point surprises', function () {
    expect(Money::of('0.10')->plus('0.20')->equals('0.30'))->toBeTrue()
        ->and(Money::of('100.00')->greaterThan('99.99'))->toBeTrue()
        ->and(Money::of('-5.00')->lessThan('0'))->toBeTrue();
});

it('formats for display without being used for arithmetic', function () {
    expect(Money::of('1699907')->format())->toBe('1,699,907.00')
        ->and(Money::of('-1699907')->format(withCurrency: true))->toBe('Rs. -1,699,907.00');
});

it('is immutable', function () {
    $original = Money::of('100.00');
    $original->plus('50.00');

    expect($original->toDecimal())->toBe('100.00');
});

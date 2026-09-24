<?php

namespace App\Support;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * An immutable money value in PKR, held as a fixed-point decimal string.
 *
 * Money never touches a PHP float. Columns are DECIMAL(18,2); every operation
 * here goes through bcmath at a fixed scale, so 0.1 + 0.2 is 0.30 and stays
 * 0.30 no matter how many times it is added up.
 */
final class Money implements JsonSerializable, Stringable
{
    public const SCALE = 2;

    private function __construct(private readonly string $amount) {}

    public static function of(string|int|float|self|null $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if ($value === null || $value === '') {
            return self::zero();
        }

        if (is_float($value)) {
            // Tolerated at the boundary (form input, legacy data) but normalised
            // immediately so no float arithmetic ever happens downstream.
            $value = number_format($value, self::SCALE, '.', '');
        }

        $normalised = str_replace([',', ' ', '_'], '', (string) $value);

        if (! preg_match('/^-?\d+(\.\d+)?$/', $normalised)) {
            throw new InvalidArgumentException("Not a valid money value: [{$value}].");
        }

        return new self(bcadd($normalised, '0', self::SCALE));
    }

    public static function zero(): self
    {
        return new self(bcadd('0', '0', self::SCALE));
    }

    public function plus(string|int|float|self $other): self
    {
        return new self(bcadd($this->amount, self::of($other)->amount, self::SCALE));
    }

    public function minus(string|int|float|self $other): self
    {
        return new self(bcsub($this->amount, self::of($other)->amount, self::SCALE));
    }

    public function times(string|int|float $factor): self
    {
        return new self(bcmul($this->amount, (string) $factor, self::SCALE));
    }

    /**
     * A percentage of this amount, rounded half up to the paisa.
     *
     * Discounts are quoted as rates and settled as amounts, so the rounding has
     * to happen once, here, and in a way that is easy to say out loud: 13% of
     * 28,492.80 is 3,704.06, not 3,704.0564. bcmath truncates rather than
     * rounds, so the half is added before the truncation does the work.
     */
    public function percent(string|int|float $percent): self
    {
        if (is_float($percent)) {
            $percent = number_format($percent, 6, '.', '');
        }

        $exact = bcdiv(bcmul($this->amount, (string) $percent, 8), '100', 8);

        return new self(self::roundHalfUp($exact));
    }

    /**
     * The figure that, less this percentage, comes back to this amount.
     *
     * A trade bill is quoted the other way round from how it is settled: the
     * rate is printed and a percentage comes off it. Given what is actually
     * charged, this is the rate that has to be printed for the discount to
     * land on it — 85.00 less 15% is quoted as 100.00.
     *
     * The inverse of percent(), and rounded the same way, so the two can be
     * read together on one document without a paisa appearing from nowhere.
     */
    public function beforeDiscount(string|int|float $percent): self
    {
        $remaining = bcsub('100', (string) $percent, 8);

        if (bccomp($remaining, '0', 8) <= 0) {
            throw new \InvalidArgumentException('A discount of 100% or more has no rate behind it.');
        }

        return new self(self::roundHalfUp(
            bcdiv(bcmul($this->amount, '100', 8), $remaining, 8)
        ));
    }

    private static function roundHalfUp(string $value): string
    {
        $negative = str_starts_with($value, '-');
        $magnitude = ltrim($value, '-');

        // 0.005 at scale 2: adding it and letting bcadd truncate rounds half up.
        $half = '0.'.str_repeat('0', self::SCALE).'5';
        $rounded = bcadd($magnitude, $half, self::SCALE);

        return ($negative ? '-' : '').$rounded;
    }

    public function negated(): self
    {
        return self::zero()->minus($this);
    }

    public function absolute(): self
    {
        return $this->isNegative() ? $this->negated() : $this;
    }

    /** -1, 0 or 1. */
    public function compareTo(string|int|float|self $other): int
    {
        return bccomp($this->amount, self::of($other)->amount, self::SCALE);
    }

    public function equals(string|int|float|self $other): bool
    {
        return $this->compareTo($other) === 0;
    }

    public function greaterThan(string|int|float|self $other): bool
    {
        return $this->compareTo($other) === 1;
    }

    public function lessThan(string|int|float|self $other): bool
    {
        return $this->compareTo($other) === -1;
    }

    public function isZero(): bool
    {
        return $this->compareTo(self::zero()) === 0;
    }

    public function isNegative(): bool
    {
        return $this->compareTo(self::zero()) === -1;
    }

    public function isPositive(): bool
    {
        return $this->compareTo(self::zero()) === 1;
    }

    /** @param iterable<Money|string|int|float> $values */
    public static function sum(iterable $values): self
    {
        $total = self::zero();

        foreach ($values as $value) {
            $total = $total->plus($value);
        }

        return $total;
    }

    /** The database representation: a plain fixed-point string. */
    public function toDecimal(): string
    {
        return $this->amount;
    }

    /** Grouped for display, e.g. "1,699,907.00". Never used for arithmetic. */
    public function format(bool $withCurrency = false): string
    {
        [$whole, $fraction] = explode('.', ltrim($this->amount, '-').'.00');
        $formatted = number_format((float) $whole, 0, '.', ',').'.'.substr($fraction, 0, 2);
        $formatted = ($this->isNegative() ? '-' : '').$formatted;

        return $withCurrency ? "Rs. {$formatted}" : $formatted;
    }

    public function jsonSerialize(): string
    {
        return $this->amount;
    }

    public function __toString(): string
    {
        return $this->amount;
    }
}

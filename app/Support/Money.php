<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * An exact amount of US dollars, stored as integer nanodollars (1 USD = 1,000,000,000).
 *
 * Pixelforge reports costs as decimal strings with nine places, so nanodollars represent
 * every amount it can produce without rounding. Floats are never used.
 */
final readonly class Money
{
    public const int NANOS_PER_DOLLAR = 1_000_000_000;

    private const int DECIMALS = 9;

    private function __construct(public int $nanos) {}

    public static function nanos(int $nanos): self
    {
        return new self($nanos);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Parse a decimal USD string such as "12.50" or "0.373875000".
     */
    public static function fromDecimal(string $amount): self
    {
        if (! preg_match('/^(-)?(\d+)(?:\.(\d{1,'.self::DECIMALS.'}))?$/', $amount, $matches)) {
            throw new InvalidArgumentException("Invalid money amount [{$amount}].");
        }

        $nanos = (int) $matches[2] * self::NANOS_PER_DOLLAR
            + (int) str_pad($matches[3] ?? '', self::DECIMALS, '0');

        return new self($matches[1] === '-' ? -$nanos : $nanos);
    }

    public function plus(self $other): self
    {
        return new self($this->nanos + $other->nanos);
    }

    public function minus(self $other): self
    {
        return new self($this->nanos - $other->nanos);
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->nanos > $other->nanos;
    }

    public function isLessThan(self $other): bool
    {
        return $this->nanos < $other->nanos;
    }

    public function equals(self $other): bool
    {
        return $this->nanos === $other->nanos;
    }

    /**
     * Add a markup given in basis points (2500 = 25%), rounding up to the next nanodollar.
     *
     * Rounding up means the rounding error is always in the operator's favour and is
     * always less than one nanodollar.
     */
    public function withMarkup(int $basisPoints): self
    {
        if ($this->nanos < 0 || $basisPoints < 0) {
            throw new InvalidArgumentException('Markup applies to non-negative amounts only.');
        }

        $numerator = $this->nanos * (10_000 + $basisPoints);

        return new self(intdiv($numerator + 9_999, 10_000));
    }

    /**
     * Format as a decimal string with nine places, e.g. "0.373875000".
     */
    public function toDecimal(): string
    {
        $absolute = abs($this->nanos);
        $dollars = intdiv($absolute, self::NANOS_PER_DOLLAR);
        $fraction = str_pad((string) ($absolute % self::NANOS_PER_DOLLAR), self::DECIMALS, '0', STR_PAD_LEFT);

        return ($this->nanos < 0 ? '-' : '')."{$dollars}.{$fraction}";
    }
}

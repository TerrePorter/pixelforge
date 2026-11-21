<?php

namespace App\Services\Pixelforge\Pricing;

use App\Support\Money;
use InvalidArgumentException;

/**
 * Pixelforge's prices in USD per one million tokens.
 */
final readonly class Rates
{
    public function __construct(
        public Money $inputTextTokens,
        public Money $inputImageTokens,
        public Money $outputTokens,
        public Money $reasoningTokens,
    ) {}

    /**
     * Build from a Pixelforge `rates` object, e.g. {"input_text_tokens": "5.00", ...}.
     *
     * @param  array<array-key, mixed>  $rates
     */
    public static function fromArray(array $rates): self
    {
        return new self(
            self::rate($rates, 'input_text_tokens'),
            self::rate($rates, 'input_image_tokens'),
            self::rate($rates, 'output_tokens'),
            self::rate($rates, 'reasoning_tokens'),
        );
    }

    /**
     * @return array{input_text_tokens: string, input_image_tokens: string, output_tokens: string, reasoning_tokens: string}
     */
    public function toArray(): array
    {
        return [
            'input_text_tokens' => $this->inputTextTokens->toDecimal(),
            'input_image_tokens' => $this->inputImageTokens->toDecimal(),
            'output_tokens' => $this->outputTokens->toDecimal(),
            'reasoning_tokens' => $this->reasoningTokens->toDecimal(),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $rates
     */
    private static function rate(array $rates, string $key): Money
    {
        $value = $rates[$key] ?? null;

        if (! is_string($value)) {
            throw new InvalidArgumentException("Pixelforge rate [{$key}] is missing or not a decimal string.");
        }

        return Money::fromDecimal($value);
    }
}

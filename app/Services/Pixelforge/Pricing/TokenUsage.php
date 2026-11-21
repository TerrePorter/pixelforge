<?php

namespace App\Services\Pixelforge\Pricing;

use InvalidArgumentException;

final readonly class TokenUsage
{
    public function __construct(
        public int $inputTextTokens,
        public int $inputImageTokens,
        public int $outputTokens,
        public int $reasoningTokens,
    ) {}

    /**
     * Build from a Pixelforge `usage` object.
     *
     * @param  array<array-key, mixed>  $usage
     */
    public static function fromArray(array $usage): self
    {
        return new self(
            self::count($usage, 'input_text_tokens'),
            self::count($usage, 'input_image_tokens'),
            self::count($usage, 'output_tokens'),
            self::count($usage, 'reasoning_tokens'),
        );
    }

    public function total(): int
    {
        return $this->inputTextTokens + $this->inputImageTokens + $this->outputTokens + $this->reasoningTokens;
    }

    /**
     * @return array{input_text_tokens: int, input_image_tokens: int, output_tokens: int, reasoning_tokens: int}
     */
    public function toArray(): array
    {
        return [
            'input_text_tokens' => $this->inputTextTokens,
            'input_image_tokens' => $this->inputImageTokens,
            'output_tokens' => $this->outputTokens,
            'reasoning_tokens' => $this->reasoningTokens,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $usage
     */
    private static function count(array $usage, string $key): int
    {
        $value = $usage[$key] ?? null;

        if (! is_int($value) || $value < 0) {
            throw new InvalidArgumentException("Pixelforge usage [{$key}] is missing or not a non-negative integer.");
        }

        return $value;
    }
}

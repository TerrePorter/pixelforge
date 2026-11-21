<?php

namespace App\Services\Pixelforge\Pricing;

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use InvalidArgumentException;

/**
 * Pixelforge's published rates and token rules, as returned by GET /v1/pricing.
 */
final readonly class PricingTable
{
    /**
     * @param  array<string, array<string, int>>  $outputTokens  quality => size => tokens
     * @param  array<string, array{min: int, max: int}>  $reasoningTokens  quality => range
     */
    public function __construct(
        public Rates $rates,
        public int $charactersPerTextToken,
        public int $textTokenVarianceBasisPoints,
        public int $tokensPerReferenceImage,
        private array $outputTokens,
        private array $reasoningTokens,
    ) {}

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $tokens = self::arrayAt($payload, 'tokens');
        $output = self::arrayAt($tokens, 'output');
        $reasoning = self::arrayAt($tokens, 'reasoning');

        $outputTokens = [];
        $reasoningTokens = [];

        foreach (ImageQuality::cases() as $quality) {
            $sizes = self::arrayAt($output, $quality->value);

            foreach (ImageSize::cases() as $size) {
                $outputTokens[$quality->value][$size->value] = self::intAt($sizes, $size->value);
            }

            $range = self::arrayAt($reasoning, $quality->value);
            $reasoningTokens[$quality->value] = [
                'min' => self::intAt($range, 'min'),
                'max' => self::intAt($range, 'max'),
            ];
        }

        $variance = $tokens['text_token_variance'] ?? null;

        if (! is_int($variance) && ! is_float($variance)) {
            throw new InvalidArgumentException('Pixelforge pricing [text_token_variance] is missing.');
        }

        return new self(
            rates: Rates::fromArray(self::arrayAt($payload, 'rates')),
            charactersPerTextToken: self::intAt($tokens, 'characters_per_text_token'),
            // 0.15 → 1500 basis points, so all later maths stays in integers.
            textTokenVarianceBasisPoints: (int) round($variance * 10_000),
            tokensPerReferenceImage: self::intAt($tokens, 'per_reference_image'),
            outputTokens: $outputTokens,
            reasoningTokens: $reasoningTokens,
        );
    }

    public function outputTokens(ImageQuality $quality, ImageSize $size): int
    {
        return $this->outputTokens[$quality->value][$size->value];
    }

    /**
     * @return array{min: int, max: int}
     */
    public function reasoningTokens(ImageQuality $quality): array
    {
        return $this->reasoningTokens[$quality->value];
    }

    /**
     * The same shape as the Pixelforge payload, so it can be cached and sent to the browser.
     *
     * @return array{rates: array<string, string>, tokens: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'rates' => $this->rates->toArray(),
            'tokens' => [
                'characters_per_text_token' => $this->charactersPerTextToken,
                'text_token_variance' => $this->textTokenVarianceBasisPoints / 10_000,
                'per_reference_image' => $this->tokensPerReferenceImage,
                'output' => $this->outputTokens,
                'reasoning' => $this->reasoningTokens,
            ],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private static function arrayAt(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if (! is_array($value)) {
            throw new InvalidArgumentException("Pixelforge pricing [{$key}] is missing.");
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function intAt(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        if (! is_int($value) || $value < 0) {
            throw new InvalidArgumentException("Pixelforge pricing [{$key}] is missing or not a non-negative integer.");
        }

        return $value;
    }
}

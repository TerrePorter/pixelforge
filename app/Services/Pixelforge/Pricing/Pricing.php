<?php

namespace App\Services\Pixelforge\Pricing;

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Support\Money;

/**
 * Works out what a generation costs us (Pixelforge's price) and what it costs the user (plus markup).
 *
 * Everything is integer arithmetic on nanodollars and token counts.
 */
final readonly class Pricing
{
    private const int TOKENS_PER_RATE_UNIT = 1_000_000;

    public function __construct(private int $markupBasisPoints) {}

    public function estimate(
        PricingTable $table,
        string $prompt,
        ImageSize $size,
        ImageQuality $quality,
        int $referenceImageCount,
    ): CostEstimate {
        // Pixelforge's own estimate: ceil(characters / 4). The real count lands within ±15% of it.
        $baseTextTokens = self::ceilDiv(mb_strlen($prompt), $table->charactersPerTextToken);
        $variance = $table->textTokenVarianceBasisPoints;

        $imageTokens = $referenceImageCount * $table->tokensPerReferenceImage;
        $outputTokens = $table->outputTokens($quality, $size);
        $reasoning = $table->reasoningTokens($quality);

        $minUsage = new TokenUsage(
            inputTextTokens: intdiv($baseTextTokens * (10_000 - $variance), 10_000),
            inputImageTokens: $imageTokens,
            outputTokens: $outputTokens,
            reasoningTokens: $reasoning['min'],
        );

        $maxUsage = new TokenUsage(
            inputTextTokens: self::ceilDiv($baseTextTokens * (10_000 + $variance), 10_000),
            inputImageTokens: $imageTokens,
            outputTokens: $outputTokens,
            reasoningTokens: $reasoning['max'],
        );

        $minProviderCost = $this->cost($minUsage, $table->rates);
        $maxProviderCost = $this->cost($maxUsage, $table->rates);

        return new CostEstimate(
            minUsage: $minUsage,
            maxUsage: $maxUsage,
            minProviderCost: $minProviderCost,
            maxProviderCost: $maxProviderCost,
            minCharge: $this->charge($minProviderCost),
            maxCharge: $this->charge($maxProviderCost),
        );
    }

    /**
     * What Pixelforge charges for the given usage at the given rates (per 1M tokens).
     */
    public function cost(TokenUsage $usage, Rates $rates): Money
    {
        $nanosPerMillionTokens = $usage->inputTextTokens * $rates->inputTextTokens->nanos
            + $usage->inputImageTokens * $rates->inputImageTokens->nanos
            + $usage->outputTokens * $rates->outputTokens->nanos
            + $usage->reasoningTokens * $rates->reasoningTokens->nanos;

        // Exact for any rate with up to three decimal places (Pixelforge publishes two).
        // For finer rates this rounds once, half up, to the nearest nanodollar.
        return Money::nanos(intdiv($nanosPerMillionTokens + self::TOKENS_PER_RATE_UNIT / 2, self::TOKENS_PER_RATE_UNIT));
    }

    /**
     * What the user pays for a given provider cost.
     */
    public function charge(Money $providerCost): Money
    {
        return $providerCost->withMarkup($this->markupBasisPoints);
    }

    private static function ceilDiv(int $numerator, int $denominator): int
    {
        return intdiv($numerator + $denominator - 1, $denominator);
    }
}

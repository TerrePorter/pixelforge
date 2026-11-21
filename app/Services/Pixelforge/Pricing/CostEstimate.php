<?php

namespace App\Services\Pixelforge\Pricing;

use App\Support\Money;

/**
 * The range a generation can cost before it runs.
 *
 * Text and reasoning token counts are only known once a generation finishes, so the
 * estimate is a range. The maximum is what we show as "up to" and hold against a balance.
 */
final readonly class CostEstimate
{
    public function __construct(
        public TokenUsage $minUsage,
        public TokenUsage $maxUsage,
        public Money $minProviderCost,
        public Money $maxProviderCost,
        public Money $minCharge,
        public Money $maxCharge,
    ) {}
}

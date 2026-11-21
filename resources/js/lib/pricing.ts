import { toNanos } from '@/lib/money';
import type {
    ImageQuality,
    ImageSize,
    PricingTable,
    TokenUsage,
} from '@/types/generation';

/**
 * The browser copy of App\Services\Pixelforge\Pricing\Pricing::estimate().
 *
 * It only drives the live preview; the server recomputes the estimate on submit and
 * its number is the one recorded. All maths is on integers (token counts and
 * nanodollars), which stay well inside Number's exact integer range.
 */

export type Estimate = {
    minUsage: TokenUsage;
    maxUsage: TokenUsage;
    minChargeNanos: number;
    maxChargeNanos: number;
};

const ceilDiv = (numerator: number, denominator: number) =>
    Math.floor((numerator + denominator - 1) / denominator);

/** Count Unicode code points, like PHP's mb_strlen. */
export const characterCount = (text: string) => [...text].length;

export function costNanos(usage: TokenUsage, table: PricingTable): number {
    const perMillion =
        usage.input_text_tokens * toNanos(table.rates.input_text_tokens) +
        usage.input_image_tokens * toNanos(table.rates.input_image_tokens) +
        usage.output_tokens * toNanos(table.rates.output_tokens) +
        usage.reasoning_tokens * toNanos(table.rates.reasoning_tokens);

    return Math.floor((perMillion + 500_000) / 1_000_000);
}

export function chargeNanos(costNanos: number, markupBasisPoints: number) {
    return ceilDiv(costNanos * (10_000 + markupBasisPoints), 10_000);
}

export function estimate(
    table: PricingTable,
    prompt: string,
    size: ImageSize,
    quality: ImageQuality,
    referenceImageCount: number,
): Estimate {
    const { tokens } = table;
    const variance = Math.round(tokens.text_token_variance * 10_000);
    const baseText = ceilDiv(
        characterCount(prompt),
        tokens.characters_per_text_token,
    );
    const images = referenceImageCount * tokens.per_reference_image;
    const output = tokens.output[quality][size];
    const reasoning = tokens.reasoning[quality];

    const minUsage: TokenUsage = {
        input_text_tokens: Math.floor(
            (baseText * (10_000 - variance)) / 10_000,
        ),
        input_image_tokens: images,
        output_tokens: output,
        reasoning_tokens: reasoning.min,
    };

    const maxUsage: TokenUsage = {
        input_text_tokens: ceilDiv(baseText * (10_000 + variance), 10_000),
        input_image_tokens: images,
        output_tokens: output,
        reasoning_tokens: reasoning.max,
    };

    return {
        minUsage,
        maxUsage,
        minChargeNanos: chargeNanos(
            costNanos(minUsage, table),
            table.markup_basis_points,
        ),
        maxChargeNanos: chargeNanos(
            costNanos(maxUsage, table),
            table.markup_basis_points,
        ),
    };
}

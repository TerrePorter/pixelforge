<?php

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Services\Pixelforge\Pricing\Pricing;
use App\Services\Pixelforge\Pricing\PricingTable;
use App\Services\Pixelforge\Pricing\Rates;
use App\Services\Pixelforge\Pricing\TokenUsage;
use App\Support\Money;

/**
 * The GET /v1/pricing response from the Pixelforge docs.
 *
 * @return array<string, mixed>
 */
function pricingPayload(): array
{
    return [
        'object' => 'pricing',
        'currency' => 'usd',
        'unit' => 'per_1m_tokens',
        'rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
        'tokens' => [
            'characters_per_text_token' => 4,
            'text_token_variance' => 0.15,
            'per_reference_image' => 765,
            'output' => [
                'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
            ],
            'reasoning' => [
                'low' => ['min' => 0, 'max' => 500],
                'medium' => ['min' => 200, 'max' => 2000],
                'high' => ['min' => 1000, 'max' => 8000],
            ],
        ],
    ];
}

function pricingService(): Pricing
{
    return new Pricing(markupBasisPoints: 2500);
}

function pricingTable(): PricingTable
{
    return PricingTable::fromArray(pricingPayload());
}

test('the docs worked example: worst case is exactly $0.373875', function () {
    // high, 1536x1024, 400-character prompt, 2 reference images
    $estimate = pricingService()->estimate(pricingTable(), str_repeat('a', 400), ImageSize::Landscape, ImageQuality::High, 2);

    expect($estimate->maxUsage)->toEqual(new TokenUsage(
        inputTextTokens: 115,
        inputImageTokens: 1530,
        outputTokens: 6450,
        reasoningTokens: 8000,
    ))
        ->and($estimate->maxProviderCost->toDecimal())->toBe('0.373875000')
        ->and($estimate->maxCharge->toDecimal())->toBe('0.467343750');
});

test('the best case uses the low end of text variance and reasoning', function () {
    $estimate = pricingService()->estimate(pricingTable(), str_repeat('a', 400), ImageSize::Landscape, ImageQuality::High, 2);

    // text: 100 base tokens − 15% = 85; reasoning minimum for high is 1000
    expect($estimate->minUsage)->toEqual(new TokenUsage(85, 1530, 6450, 1000))
        // 85×5 + 1530×10 + 6450×40 + 1000×12.5 = 286,225 per 1M → $0.286225
        ->and($estimate->minProviderCost->toDecimal())->toBe('0.286225000');
});

test('the docs usage example costs exactly $0.169080', function () {
    $cost = pricingService()->cost(
        new TokenUsage(inputTextTokens: 41, inputImageTokens: 0, outputTokens: 3300, reasoningTokens: 2950),
        pricingTable()->rates,
    );

    expect($cost->toDecimal())->toBe('0.169080000');
});

test('output tokens come from the table for every size and quality', function (ImageQuality $quality, ImageSize $size, int $tokens) {
    $estimate = pricingService()->estimate(pricingTable(), 'x', $size, $quality, 0);

    expect($estimate->minUsage->outputTokens)->toBe($tokens)
        ->and($estimate->maxUsage->outputTokens)->toBe($tokens);
})->with([
    [ImageQuality::Low, ImageSize::Square, 280],
    [ImageQuality::Low, ImageSize::Landscape, 420],
    [ImageQuality::Low, ImageSize::Portrait, 420],
    [ImageQuality::Medium, ImageSize::Square, 1100],
    [ImageQuality::Medium, ImageSize::Landscape, 1650],
    [ImageQuality::Medium, ImageSize::Portrait, 1650],
    [ImageQuality::High, ImageSize::Square, 4300],
    [ImageQuality::High, ImageSize::Landscape, 6450],
    [ImageQuality::High, ImageSize::Portrait, 6450],
]);

test('text tokens count unicode characters, not bytes', function () {
    // 8 characters (each multi-byte) → ceil(8 / 4) = 2 base tokens → max ceil(2 × 1.15) = 3
    $estimate = pricingService()->estimate(pricingTable(), 'ñéü日本語🎨✨', ImageSize::Square, ImageQuality::Low, 0);

    expect($estimate->minUsage->inputTextTokens)->toBe(1)
        ->and($estimate->maxUsage->inputTextTokens)->toBe(3);
});

test('text tokens round the base estimate up', function () {
    // 5 characters → ceil(5 / 4) = 2
    $estimate = pricingService()->estimate(pricingTable(), 'hello', ImageSize::Square, ImageQuality::Low, 0);

    expect($estimate->maxUsage->inputTextTokens)->toBe(3); // ceil(2 × 1.15)
});

test('each reference image adds exactly 765 image tokens', function (int $references) {
    $estimate = pricingService()->estimate(pricingTable(), 'x', ImageSize::Square, ImageQuality::Low, $references);

    expect($estimate->minUsage->inputImageTokens)->toBe($references * 765)
        ->and($estimate->maxUsage->inputImageTokens)->toBe($references * 765);
})->with([0, 1, 4]);

test('the charge is the provider cost plus 25%, rounded up to the nanodollar', function () {
    expect(pricingService()->charge(Money::fromDecimal('0.169080000'))->toDecimal())->toBe('0.211350000')
        ->and(pricingService()->charge(Money::nanos(1))->nanos)->toBe(2);
});

test('the actual cost uses the rates fixed on the generation, not current pricing', function () {
    $doubled = Rates::fromArray([
        'input_text_tokens' => '10.00',
        'input_image_tokens' => '20.00',
        'output_tokens' => '80.00',
        'reasoning_tokens' => '25.00',
    ]);

    $usage = new TokenUsage(41, 0, 3300, 2950);

    expect(pricingService()->cost($usage, $doubled)->toDecimal())->toBe('0.338160000');
});

test('rates are parsed from strings', function () {
    $rates = pricingTable()->rates;

    expect($rates->inputTextTokens->toDecimal())->toBe('5.000000000')
        ->and($rates->reasoningTokens->toDecimal())->toBe('12.500000000');
});

test('token usage is read from a Pixelforge usage object', function () {
    $usage = TokenUsage::fromArray([
        'input_text_tokens' => 41,
        'input_image_tokens' => 765,
        'output_tokens' => 3300,
        'reasoning_tokens' => 2950,
        'total_tokens' => 7056,
    ]);

    expect($usage)->toEqual(new TokenUsage(41, 765, 3300, 2950));
});

test('a malformed pricing payload is rejected', function () {
    $payload = pricingPayload();
    unset($payload['tokens']['output']['high']);

    PricingTable::fromArray($payload);
})->throws(InvalidArgumentException::class);

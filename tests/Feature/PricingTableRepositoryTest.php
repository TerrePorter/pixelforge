<?php

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Services\Pixelforge\Pricing\PricingTableRepository;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('pixelforge.base_url', 'https://pixelforge.test/v1');
});

test('loads pricing from Pixelforge and caches it', function () {
    $payload = config('pixelforge.pricing.fallback');
    $payload['rates']['output_tokens'] = '42.00';

    Http::fake(['pixelforge.test/v1/pricing' => Http::response($payload)]);

    $repository = app(PricingTableRepository::class);
    $first = $repository->current();
    $second = $repository->current();

    expect($first->rates->outputTokens->toDecimal())->toBe('42.000000000')
        ->and($second->rates->outputTokens->toDecimal())->toBe('42.000000000')
        ->and($second->outputTokens(ImageQuality::High, ImageSize::Landscape))->toBe(6450);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => ! $request->hasHeader('Authorization'));
});

test('falls back to the configured snapshot when Pixelforge is down, and retries next time', function () {
    Http::fakeSequence('pixelforge.test/v1/pricing')
        ->push(['error' => ['code' => 'overloaded']], 503)
        ->push(config('pixelforge.pricing.fallback'));

    $repository = app(PricingTableRepository::class);

    expect($repository->current()->rates->outputTokens->toDecimal())->toBe('40.000000000');

    $repository->current();

    Http::assertSentCount(2);
});

test('falls back when Pixelforge returns a malformed payload', function () {
    Http::fake(['pixelforge.test/v1/pricing' => Http::response(['rates' => []])]);

    expect(app(PricingTableRepository::class)->current()->rates->reasoningTokens->toDecimal())
        ->toBe('12.500000000');
});

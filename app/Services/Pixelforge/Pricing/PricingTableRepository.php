<?php

namespace App\Services\Pixelforge\Pricing;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Provides Pixelforge's current pricing for estimates.
 *
 * GET /v1/pricing needs no auth. A good response is cached; if Pixelforge can't be
 * reached we fall back to the configured snapshot (not cached), so the estimate shown
 * on the generate page keeps working during an outage.
 */
class PricingTableRepository
{
    private const string CACHE_KEY = 'pixelforge.pricing';

    public function current(): PricingTable
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            return PricingTable::fromArray($cached);
        }

        $table = $this->fetch();

        if ($table === null) {
            /** @var array<string, mixed> $fallback */
            $fallback = config('pixelforge.pricing.fallback');

            return PricingTable::fromArray($fallback);
        }

        Cache::put(self::CACHE_KEY, $table->toArray(), config()->integer('pixelforge.pricing.cache_seconds'));

        return $table;
    }

    private function fetch(): ?PricingTable
    {
        try {
            $response = Http::baseUrl(config()->string('pixelforge.base_url'))
                ->acceptJson()
                ->timeout(5)
                ->get('pricing')
                ->throw();

            return PricingTable::fromArray((array) $response->json());
        } catch (Throwable $e) {
            Log::warning('Could not load Pixelforge pricing, using the configured fallback.', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}

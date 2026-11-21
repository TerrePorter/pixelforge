<?php

namespace Database\Factories;

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Models\Generation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Defaults to a medium 1024x1024 generation with no reference images. The estimates are
 * Pixelforge's published rates for that size and quality, ignoring the few text tokens.
 *
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'idempotency_key' => (string) Str::uuid(),
            'status' => GenerationStatus::Pending,
            'prompt' => fake()->sentence(),
            'size' => ImageSize::Square,
            'quality' => ImageQuality::Medium,
            'reference_images' => [],
            // min: 1100 output + 200 reasoning tokens; max: 1100 output + 2000 reasoning tokens
            'estimated_min_cost_nanos' => 46_500_000,
            'estimated_max_cost_nanos' => 69_000_000,
            'estimated_min_charge_nanos' => 58_125_000,
            'estimated_max_charge_nanos' => 86_250_000,
        ];
    }

    public function submitted(?string $providerId = null): static
    {
        return $this->state(fn () => [
            'status' => GenerationStatus::Queued,
        ])->afterMaking(function (Generation $generation) use ($providerId) {
            $generation->provider_id = $providerId ?? 'gen_'.Str::random(16);
            $generation->submitted_at = CarbonImmutable::now();
        });
    }
}

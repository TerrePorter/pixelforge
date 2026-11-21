<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A webhook event received from Pixelforge, kept for deduplication and auditing.
 *
 * @property int $id
 * @property string $event_id
 * @property string $type
 * @property string|null $provider_generation_id
 * @property int|null $delivery_attempt
 * @property array<array-key, mixed> $payload
 * @property CarbonImmutable|null $processed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class WebhookEvent extends Model
{
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'delivery_attempt' => 'integer',
            'processed_at' => 'datetime',
        ];
    }
}

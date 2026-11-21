<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $idempotency_key
 * @property string|null $provider_id
 * @property GenerationStatus $status
 * @property string|null $last_submission_error
 * @property string $prompt
 * @property ImageSize $size
 * @property ImageQuality $quality
 * @property list<string> $reference_images
 * @property int $estimated_min_cost_nanos
 * @property int $estimated_max_cost_nanos
 * @property int $estimated_min_charge_nanos
 * @property int $estimated_max_charge_nanos
 * @property int|null $input_text_tokens
 * @property int|null $input_image_tokens
 * @property int|null $output_tokens
 * @property int|null $reasoning_tokens
 * @property array<string, string>|null $rates
 * @property int|null $cost_nanos
 * @property int|null $charge_nanos
 * @property string|null $image_url
 * @property CarbonImmutable|null $image_url_expires_at
 * @property string|null $error_code
 * @property string|null $error_message
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $last_synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable([
    'user_id',
    'idempotency_key',
    'status',
    'prompt',
    'size',
    'quality',
    'reference_images',
    'estimated_min_cost_nanos',
    'estimated_max_cost_nanos',
    'estimated_min_charge_nanos',
    'estimated_max_charge_nanos',
])]
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'size' => ImageSize::class,
            'quality' => ImageQuality::class,
            'reference_images' => 'array',
            'rates' => 'array',
            'estimated_min_cost_nanos' => 'integer',
            'estimated_max_cost_nanos' => 'integer',
            'estimated_min_charge_nanos' => 'integer',
            'estimated_max_charge_nanos' => 'integer',
            'cost_nanos' => 'integer',
            'charge_nanos' => 'integer',
            'image_url_expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<CreditTransaction, $this>
     */
    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }

    /**
     * The worst-case price, held against the user's balance while the generation runs.
     */
    public function estimatedMaxCharge(): Money
    {
        return Money::nanos($this->estimated_max_charge_nanos);
    }
}

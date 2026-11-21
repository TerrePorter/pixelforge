<?php

namespace App\Http\Resources;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A generation as the history list shows it. Money is sent as 9-decimal USD strings.
 *
 * @mixin Generation
 */
class GenerationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $succeeded = $this->status === GenerationStatus::Succeeded;

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'waiting_reason' => $this->status === GenerationStatus::Pending ? $this->last_submission_error : null,
            'prompt' => $this->prompt,
            'size' => $this->size->value,
            'quality' => $this->quality->value,
            'reference_images' => $this->reference_images,
            'estimated_min_charge' => Money::nanos($this->estimated_min_charge_nanos)->toDecimal(),
            'estimated_max_charge' => Money::nanos($this->estimated_max_charge_nanos)->toDecimal(),
            'charge' => $this->charge_nanos === null ? null : Money::nanos($this->charge_nanos)->toDecimal(),
            'usage' => $succeeded ? [
                'input_text_tokens' => $this->input_text_tokens,
                'input_image_tokens' => $this->input_image_tokens,
                'output_tokens' => $this->output_tokens,
                'reasoning_tokens' => $this->reasoning_tokens,
            ] : null,
            'image_url' => $succeeded ? $this->image_url : null,
            'image_expired' => $succeeded && $this->image_url_expires_at?->isPast() === true,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'created_at' => $this->created_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}

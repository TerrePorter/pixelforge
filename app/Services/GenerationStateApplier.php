<?php

namespace App\Services;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Pixelforge\Pricing\Pricing;
use App\Services\Pixelforge\Pricing\Rates;
use App\Services\Pixelforge\Pricing\TokenUsage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * The one place a generation's status and cost change.
 *
 * Webhooks, polling and the create response all hand Pixelforge's generation object
 * to apply(). Each call locks the row, only ever moves the status forward, and never
 * leaves a terminal state, so duplicate and out-of-order events are harmless. Money is
 * recorded exactly once: on the transition into succeeded or failed.
 */
class GenerationStateApplier
{
    public function __construct(
        private readonly Pricing $pricing,
        private readonly Ledger $ledger,
    ) {}

    /**
     * Apply a Pixelforge generation object (from a webhook, a poll or the create response).
     *
     * @param  array<array-key, mixed>  $remote
     */
    public function apply(Generation $generation, array $remote): Generation
    {
        $providerId = $remote['id'] ?? null;
        $status = GenerationStatus::tryFrom(is_string($remote['status'] ?? null) ? $remote['status'] : '');

        if (! is_string($providerId) || $status === null || $status === GenerationStatus::Pending) {
            throw new InvalidArgumentException('Not a valid Pixelforge generation object.');
        }

        return DB::transaction(function () use ($generation, $remote, $providerId, $status) {
            $locked = Generation::query()->lockForUpdate()->findOrFail($generation->id);

            if ($locked->provider_id !== null && $locked->provider_id !== $providerId) {
                throw new LogicException("Generation {$locked->id} belongs to {$locked->provider_id}, not {$providerId}.");
            }

            // A webhook can arrive before our create request has returned, so fill these in here too.
            $locked->provider_id = $providerId;
            $locked->submitted_at ??= CarbonImmutable::now();
            $locked->last_submission_error = null;
            $locked->last_synced_at = CarbonImmutable::now();

            if ($status->isAfter($locked->status)) {
                $this->transition($locked, $status, $remote);
            }

            $locked->save();

            if ($locked->wasChanged('status') && $locked->status->isTerminal()) {
                $this->ledger->settle($locked);
            }

            return $locked;
        });
    }

    /**
     * Fail a generation that never reached Pixelforge (rejected, or out of retries).
     * Does nothing if Pixelforge has already accepted it.
     */
    public function failBeforeSubmission(Generation $generation, string $code, string $message): Generation
    {
        return DB::transaction(function () use ($generation, $code, $message) {
            $locked = Generation::query()->lockForUpdate()->findOrFail($generation->id);

            if ($locked->status !== GenerationStatus::Pending) {
                return $locked;
            }

            $this->markFailed($locked, $code, $message, CarbonImmutable::now());
            $locked->save();

            $this->ledger->settle($locked);

            return $locked;
        });
    }

    /**
     * @param  array<array-key, mixed>  $remote
     */
    private function transition(Generation $generation, GenerationStatus $status, array $remote): void
    {
        $startedAt = $this->time($remote, 'started_at');
        $completedAt = $this->time($remote, 'completed_at') ?? CarbonImmutable::now();

        if ($startedAt !== null) {
            $generation->started_at ??= $startedAt;
        }

        match ($status) {
            GenerationStatus::Queued, GenerationStatus::Pending => null,
            GenerationStatus::Processing => $generation->started_at ??= CarbonImmutable::now(),
            GenerationStatus::Succeeded => $this->markSucceeded($generation, $remote, $completedAt),
            GenerationStatus::Failed => $this->markFailed(
                $generation,
                $this->string($remote['error'] ?? null, 'code') ?? 'unknown_error',
                $this->string($remote['error'] ?? null, 'message') ?? 'The generation failed.',
                $completedAt,
            ),
        };

        $generation->status = $status;
    }

    /**
     * @param  array<array-key, mixed>  $remote
     */
    private function markSucceeded(Generation $generation, array $remote, CarbonImmutable $completedAt): void
    {
        if (! is_array($remote['usage'] ?? null) || ! is_array($remote['rates'] ?? null)) {
            throw new InvalidArgumentException('A succeeded generation must include usage and rates.');
        }

        // Billed at the rates fixed on the generation when it was created, never current pricing.
        $usage = TokenUsage::fromArray($remote['usage']);
        $rates = Rates::fromArray($remote['rates']);
        $cost = $this->pricing->cost($usage, $rates);

        $generation->input_text_tokens = $usage->inputTextTokens;
        $generation->input_image_tokens = $usage->inputImageTokens;
        $generation->output_tokens = $usage->outputTokens;
        $generation->reasoning_tokens = $usage->reasoningTokens;
        $generation->rates = $remote['rates'];
        $generation->cost_nanos = $cost->nanos;
        $generation->charge_nanos = $this->pricing->charge($cost)->nanos;
        $generation->image_url = is_string($remote['image_url'] ?? null) ? $remote['image_url'] : null;
        $generation->image_url_expires_at = $this->time($remote, 'image_url_expires_at');
        $generation->started_at ??= $completedAt;
        $generation->completed_at = $completedAt;
    }

    private function markFailed(Generation $generation, string $code, string $message, CarbonImmutable $completedAt): void
    {
        $generation->status = GenerationStatus::Failed;
        $generation->error_code = $code;
        $generation->error_message = $message;
        // Failed generations are free.
        $generation->cost_nanos = 0;
        $generation->charge_nanos = 0;
        $generation->completed_at = $completedAt;
    }

    /**
     * @param  array<array-key, mixed>  $remote
     */
    private function time(array $remote, string $key): ?CarbonImmutable
    {
        $value = $remote[$key] ?? null;

        return is_string($value) ? CarbonImmutable::parse($value) : null;
    }

    private function string(mixed $data, string $key): ?string
    {
        return is_array($data) && is_string($data[$key] ?? null) ? $data[$key] : null;
    }
}

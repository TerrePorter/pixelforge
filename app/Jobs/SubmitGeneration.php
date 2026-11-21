<?php

namespace App\Jobs;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\GenerationStateApplier;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends a pending generation to Pixelforge.
 *
 * Every attempt uses the generation's own Idempotency-Key, so a retry can never create a
 * second (billable) generation: if an earlier attempt was accepted, Pixelforge just
 * replays it. Busy responses (rate, concurrency, overload) are retried after Retry-After
 * until submit_for_minutes runs out; definite rejections fail the generation for free.
 */
class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    /** Releases don't count; only unexpected exceptions do. */
    public int $tries = 0;

    public int $maxExceptions = 3;

    public function __construct(public Generation $generation) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(config()->integer('pixelforge.submit_for_minutes'));
    }

    public function handle(PixelforgeClient $client, GenerationStateApplier $applier): void
    {
        $generation = $this->generation->fresh();

        if ($generation === null || $generation->status !== GenerationStatus::Pending) {
            return; // Already accepted (e.g. a webhook beat us here) or already failed.
        }

        if ($this->pixelforgeIsFull()) {
            $this->waitAndRetry($generation, 'concurrency_limit_exceeded', 5);

            return;
        }

        try {
            $remote = $client->createGeneration($this->payload($generation), $generation->idempotency_key);
        } catch (PixelforgeException $e) {
            if ($e->isRetryable()) {
                $this->waitAndRetry($generation, $e->errorCode, $e->retryAfter ?? $this->retryDelay());

                return;
            }

            $applier->failBeforeSubmission($generation, $e->errorCode, $this->messageFor($e));

            return;
        }

        $applier->apply($generation, $remote);
    }

    /**
     * Out of time or out of attempts.
     *
     * If Pixelforge was only ever busy, it created nothing, so the generation fails for free.
     * If the last problem was a timeout or a bare 5xx, Pixelforge may have accepted it: we
     * leave it pending for reconciliation to resolve with the same Idempotency-Key, rather
     * than risk calling a billed generation "failed".
     */
    public function failed(?Throwable $exception): void
    {
        $generation = $this->generation->fresh();

        if ($generation === null || $generation->status !== GenerationStatus::Pending) {
            return;
        }

        if (in_array($generation->last_submission_error, PixelforgeException::BUSY_CODES, true)) {
            app(GenerationStateApplier::class)->failBeforeSubmission(
                $generation,
                'provider_busy',
                'Pixelforge stayed too busy to accept this generation. Please try again.',
            );

            return;
        }

        Log::warning('Could not confirm whether Pixelforge accepted a generation; leaving it for reconciliation.', [
            'generation_id' => $generation->id,
            'last_submission_error' => $generation->last_submission_error,
            'exception' => $exception?->getMessage(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Generation $generation): array
    {
        $payload = [
            'prompt' => $generation->prompt,
            'size' => $generation->size->value,
            'quality' => $generation->quality->value,
            // Lets a webhook find our row even if it arrives before this request returns.
            'metadata' => ['generation_id' => (string) $generation->id],
        ];

        if ($generation->reference_images !== []) {
            $payload['reference_images'] = $generation->reference_images;
        }

        return $payload;
    }

    /**
     * Pixelforge allows a few running generations per account. If our own records say
     * we're at the limit, wait without spending a request on a certain 429.
     */
    private function pixelforgeIsFull(): bool
    {
        return Generation::query()
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->count() >= config()->integer('pixelforge.concurrency_limit');
    }

    private function waitAndRetry(Generation $generation, string $reason, int $seconds): void
    {
        $generation->last_submission_error = $reason;
        $generation->save();

        $this->release($seconds);
    }

    private function retryDelay(): int
    {
        return min(60, 2 ** $this->attempts());
    }

    private function messageFor(PixelforgeException $e): string
    {
        return match ($e->errorCode) {
            'content_policy_violation' => 'Pixelforge blocked this prompt under its content policy.',
            'daily_limit_exceeded' => "Pixelforge's daily generation limit has been reached. Please try again after midnight UTC.",
            default => $e->getMessage(),
        };
    }
}

<?php

namespace App\Console\Commands;

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Services\GenerationStateApplier;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The fallback for when webhooks don't arrive (none configured, endpoint down, all
 * retries failed) and for submissions whose job never ran or gave up uncertain.
 *
 *  - Unfinished generations with no update for poll_after_seconds are fetched from
 *    Pixelforge and applied, exactly as a webhook would be.
 *  - Pending generations are re-dispatched. The job is unique per generation and
 *    always sends the same Idempotency-Key, so this never creates a duplicate.
 */
class ReconcileGenerations extends Command
{
    protected $signature = 'generations:reconcile {--limit=20 : Most generations to poll per run}';

    protected $description = 'Poll Pixelforge for unfinished generations and resubmit stuck ones';

    public function handle(PixelforgeClient $client, GenerationStateApplier $applier): int
    {
        $staleBefore = now()->subSeconds(config()->integer('pixelforge.poll_after_seconds'));

        $resubmitted = $this->resubmitPending($staleBefore);
        [$polled, $finished] = $this->pollUnfinished($client, $applier, $staleBefore);

        $this->info("Polled {$polled}, finished {$finished}, resubmitted {$resubmitted}.");

        return self::SUCCESS;
    }

    private function resubmitPending(\DateTimeInterface $staleBefore): int
    {
        $pending = Generation::query()
            ->where('status', GenerationStatus::Pending)
            ->where('created_at', '<=', $staleBefore)
            ->get();

        foreach ($pending as $generation) {
            // A no-op while this generation's job is still queued or waiting to retry.
            SubmitGeneration::dispatch($generation);
        }

        return $pending->count();
    }

    /**
     * @return array{int, int}
     */
    private function pollUnfinished(PixelforgeClient $client, GenerationStateApplier $applier, \DateTimeInterface $staleBefore): array
    {
        $stale = Generation::query()
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->whereNotNull('provider_id')
            ->where(fn ($query) => $query
                ->where('last_synced_at', '<=', $staleBefore)
                ->orWhereNull('last_synced_at'))
            ->orderByRaw('coalesce(last_synced_at, created_at)')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        $polled = 0;
        $finished = 0;

        foreach ($stale as $generation) {
            /** @var string $providerId */
            $providerId = $generation->provider_id;

            try {
                $remote = $client->getGeneration($providerId);
            } catch (PixelforgeException $e) {
                if ($e->isBusy()) {
                    // Out of request budget: stop and let the next run carry on.
                    $this->warn("Pixelforge is busy ({$e->errorCode}); stopping this run.");

                    break;
                }

                Log::warning('Could not poll a Pixelforge generation.', [
                    'generation_id' => $generation->id,
                    'provider_id' => $providerId,
                    'error' => $e->errorCode,
                ]);

                continue;
            }

            $polled++;

            if ($applier->apply($generation, $remote)->status->isTerminal()) {
                $finished++;
            }
        }

        return [$polled, $finished];
    }
}

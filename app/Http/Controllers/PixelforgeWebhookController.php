<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Services\GenerationStateApplier;
use App\Services\Pixelforge\WebhookSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives Pixelforge webhooks.
 *
 * Delivery is at least once and may be out of order, so this endpoint:
 *  1. verifies the signature against the raw body,
 *  2. stores each event id once (a redelivery is acknowledged and skipped),
 *  3. hands the generation object to GenerationStateApplier, which only moves status
 *     forward and records money exactly once.
 *
 * Any 2xx within 10 seconds counts as delivered; anything else makes Pixelforge retry.
 */
class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, GenerationStateApplier $applier): JsonResponse
    {
        $rawBody = $request->getContent();

        if (! $this->signature()->isValid($rawBody, $request->header('Pixelforge-Signature'), now()->getTimestamp())) {
            return response()->json(['error' => 'Invalid signature.'], 400);
        }

        $payload = json_decode($rawBody, true);
        $eventId = is_array($payload) ? ($payload['id'] ?? null) : null;
        $type = is_array($payload) ? ($payload['type'] ?? null) : null;

        if (! is_array($payload) || ! is_string($eventId) || ! is_string($type)) {
            return response()->json(['error' => 'Malformed event.'], 400);
        }

        $object = $payload['data']['object'] ?? null;
        $object = is_array($object) ? $object : null;

        $event = $this->record($eventId, $type, $object, $request, $payload);

        if ($event->processed_at !== null) {
            return response()->json(['received' => true, 'duplicate' => true]);
        }

        if (str_starts_with($type, 'generation.') && $object !== null) {
            $generation = $this->findGeneration($object);

            if ($generation === null) {
                Log::warning('Pixelforge webhook for an unknown generation.', ['event_id' => $eventId, 'object_id' => $object['id'] ?? null]);
            } else {
                // If this throws, we answer 500 and Pixelforge redelivers; reprocessing is safe.
                $applier->apply($generation, $object);
            }
        }

        $event->processed_at = now();
        $event->save();

        return response()->json(['received' => true]);
    }

    /**
     * Store the event, or fetch it if this is a redelivery.
     *
     * @param  array<array-key, mixed>|null  $object
     * @param  array<array-key, mixed>  $payload
     */
    private function record(string $eventId, string $type, ?array $object, Request $request, array $payload): WebhookEvent
    {
        $attempt = $request->header('Pixelforge-Delivery-Attempt');
        $providerId = $object['id'] ?? null;

        WebhookEvent::query()->insertOrIgnore([
            'event_id' => $eventId,
            'type' => $type,
            'provider_generation_id' => is_string($providerId) ? $providerId : null,
            'delivery_attempt' => is_numeric($attempt) ? (int) $attempt : null,
            'payload' => json_encode($payload),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return WebhookEvent::query()->where('event_id', $eventId)->firstOrFail();
    }

    /**
     * Find our row: by the id we put in metadata first (works even when the webhook beats
     * the create response), then by Pixelforge's id.
     *
     * @param  array<array-key, mixed>  $object
     */
    private function findGeneration(array $object): ?Generation
    {
        $providerId = is_string($object['id'] ?? null) ? $object['id'] : null;
        $localId = $object['metadata']['generation_id'] ?? null;

        if (is_string($localId) && ctype_digit($localId)) {
            $generation = Generation::find((int) $localId);

            if ($generation !== null && ($generation->provider_id === null || $generation->provider_id === $providerId)) {
                return $generation;
            }
        }

        return $providerId === null ? null : Generation::query()->where('provider_id', $providerId)->first();
    }

    private function signature(): WebhookSignature
    {
        return new WebhookSignature(
            secret: (string) config('pixelforge.webhook_secret'),
            toleranceSeconds: config()->integer('pixelforge.webhook_tolerance_seconds'),
        );
    }
}

<?php

namespace App\Services\Pixelforge;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * A thin wrapper around the Pixelforge REST API. Returns decoded JSON and turns every
 * non-2xx response or network failure into a PixelforgeException.
 */
class PixelforgeClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
    ) {}

    /**
     * POST /v1/generations. Returns the generation object (202, or an idempotent replay).
     *
     * @param  array<string, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public function createGeneration(array $payload, string $idempotencyKey): array
    {
        return $this->send(fn (PendingRequest $http) => $http
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('generations', $payload));
    }

    /**
     * GET /v1/generations/{id}.
     *
     * @return array<array-key, mixed>
     */
    public function getGeneration(string $providerId): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get('generations/'.rawurlencode($providerId)));
    }

    /**
     * GET /v1/usage: what Pixelforge billed this API key, per UTC day (by completion
     * day), for an inclusive date range of at most 92 days.
     *
     * @return array<array-key, mixed>
     */
    public function usage(string $startDate, string $endDate): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get('usage', [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]));
    }

    /**
     * @param  Closure(PendingRequest): Response  $request
     * @return array<array-key, mixed>
     */
    private function send(Closure $request): array
    {
        if ($this->apiKey === null || $this->apiKey === '') {
            throw PixelforgeException::notConfigured();
        }

        $http = Http::baseUrl($this->baseUrl)
            ->withToken($this->apiKey)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(20);

        try {
            $response = $request($http);
        } catch (ConnectionException $e) {
            throw PixelforgeException::connectionFailed($e);
        }

        if (! $response->successful()) {
            throw PixelforgeException::fromResponse($response);
        }

        return (array) $response->json();
    }
}

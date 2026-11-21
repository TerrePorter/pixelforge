<?php

namespace App\Services\Pixelforge;

/**
 * Verifies the Pixelforge-Signature header: "t=<unix seconds>,v1=<hex HMAC-SHA256>".
 *
 * The signed string is "<t>.<raw request body>", keyed with the webhook signing secret
 * (the whole "whsec_..." string). Old timestamps are rejected to stop replays.
 */
final readonly class WebhookSignature
{
    public function __construct(
        private string $secret,
        private int $toleranceSeconds = 300,
    ) {}

    public function isValid(string $rawBody, ?string $header, int $now): bool
    {
        if ($this->secret === '' || $header === null || $header === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === [] || abs($now - $timestamp) > $this->toleranceSeconds) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$rawBody}", $this->secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, strtolower($signature))) {
                return true;
            }
        }

        return false;
    }
}

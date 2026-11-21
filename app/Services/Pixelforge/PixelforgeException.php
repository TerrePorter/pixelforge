<?php

namespace App\Services\Pixelforge;

use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/**
 * An error from the Pixelforge API, or a failure to reach it.
 *
 * The important distinction for money is whether Pixelforge definitely created nothing.
 * A rejection with an error body (4xx, or 503 "overloaded") is definite. A timeout or a
 * bare 5xx is not: the generation may exist, so we must never treat it as failed.
 */
class PixelforgeException extends RuntimeException
{
    /** Rejections where nothing was created and trying again later can succeed. */
    public const array BUSY_CODES = [
        'rate_limit_exceeded',
        'concurrency_limit_exceeded',
        'overloaded',
    ];

    public const string CONNECTION_ERROR = 'connection_error';

    public const string SERVER_ERROR = 'server_error';

    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function fromResponse(Response $response): self
    {
        $code = $response->json('error.code');
        $message = $response->json('error.message');
        $retryAfter = $response->header('Retry-After');

        return new self(
            errorCode: is_string($code) ? $code : ($response->serverError() ? self::SERVER_ERROR : 'http_'.$response->status()),
            message: is_string($message) ? $message : "Pixelforge responded with HTTP {$response->status()}.",
            httpStatus: $response->status(),
            retryAfter: is_numeric($retryAfter) ? max(1, (int) $retryAfter) : null,
        );
    }

    public static function connectionFailed(Throwable $previous): self
    {
        return new self(self::CONNECTION_ERROR, 'Could not reach Pixelforge: '.$previous->getMessage(), previous: $previous);
    }

    public static function notConfigured(): self
    {
        return new self('not_configured', 'PIXELFORGE_API_KEY is not set.');
    }

    /**
     * Pixelforge was too busy and created nothing; the same request can be sent again later.
     */
    public function isBusy(): bool
    {
        return in_array($this->errorCode, self::BUSY_CODES, true);
    }

    /**
     * We don't know whether Pixelforge created anything. Retrying with the same
     * Idempotency-Key is safe and is the only way to find out.
     */
    public function isUncertain(): bool
    {
        return $this->errorCode === self::CONNECTION_ERROR || $this->errorCode === self::SERVER_ERROR;
    }

    public function isRetryable(): bool
    {
        return $this->isBusy() || $this->isUncertain();
    }
}

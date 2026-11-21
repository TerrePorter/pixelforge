<?php

namespace App\Enums;

enum GenerationStatus: string
{
    /** Created locally, not yet accepted by Pixelforge (e.g. waiting for a free slot). */
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }

    /**
     * Whether moving from $current to this status is progress.
     *
     * Status only ever moves forward, and terminal states are final. That is what makes
     * out-of-order events harmless: a late "processing" after "succeeded" is ignored.
     */
    public function isAfter(self $current): bool
    {
        return ! $current->isTerminal() && $this->rank() > $current->rank();
    }

    private function rank(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::Queued => 1,
            self::Processing => 2,
            self::Succeeded, self::Failed => 3,
        };
    }
}

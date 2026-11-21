<?php

namespace App\Models;

use App\Enums\CreditTransactionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line in a user's credit ledger. Rows are only ever inserted, never changed.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $generation_id
 * @property CreditTransactionType $type
 * @property int $amount_nanos
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'generation_id', 'type', 'amount_nanos', 'description'])]
class CreditTransaction extends Model
{
    protected function casts(): array
    {
        return [
            'type' => CreditTransactionType::class,
            'amount_nanos' => 'integer',
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
     * @return BelongsTo<Generation, $this>
     */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }
}

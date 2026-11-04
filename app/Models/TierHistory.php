<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change of a customer's tier. Rows are only ever added.
 */
#[Fillable(['customer_id', 'from_tier_id', 'to_tier_id', 'reason'])]
class TierHistory extends Model
{
    /**
     * Get the tier the customer left.
     *
     * @return BelongsTo<Tier, $this>
     */
    public function fromTier(): BelongsTo
    {
        return $this->belongsTo(Tier::class, 'from_tier_id');
    }

    /**
     * Get the tier the customer moved to.
     *
     * @return BelongsTo<Tier, $this>
     */
    public function toTier(): BelongsTo
    {
        return $this->belongsTo(Tier::class, 'to_tier_id');
    }

    /**
     * Shape the change for API responses.
     *
     * @return array{id: int, customer_id: int, from: string|null, to: string|null, reason: string|null, created_at: string|null}
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'from' => $this->fromTier?->name,
            'to' => $this->toTier?->name,
            'reason' => $this->reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

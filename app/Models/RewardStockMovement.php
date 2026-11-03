<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['reward_id', 'variant_id', 'location_id', 'supplier_id', 'actor_id', 'type', 'on_hand_delta', 'reserved_delta', 'batch', 'expires_at', 'serials', 'unit_cost', 'source', 'reference', 'reason'])]
class RewardStockMovement extends Model
{
    /**
     * Every ledger entry type, matching the frontend's movement types.
     *
     * @var list<string>
     */
    public const TYPES = ['RECEIVE', 'RESERVE', 'RELEASE', 'FULFILL', 'RETURN', 'ADJUST', 'TRANSFER_OUT', 'TRANSFER_IN', 'DAMAGE', 'LOSS', 'EXPIRY', 'COUNT'];

    /**
     * Get the reward the stock belongs to.
     *
     * @return BelongsTo<Reward, $this>
     */
    public function reward(): BelongsTo
    {
        return $this->belongsTo(Reward::class);
    }

    /**
     * Get the staff user who recorded the entry.
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Shape the entry for API responses. Dates are sent as ISO strings and expiry as a plain date.
     *
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'created_at' => $this->created_at?->toIso8601String(),
            'type' => $this->type,
            'reward_id' => $this->reward_id,
            'variant_id' => $this->variant_id,
            'location_id' => $this->location_id,
            'on_hand_delta' => (int) $this->on_hand_delta,
            'reserved_delta' => (int) $this->reserved_delta,
            'batch' => $this->batch,
            'expires_at' => $this->expires_at?->toDateString(),
            'serials' => $this->serials,
            'unit_cost' => (float) $this->unit_cost,
            'supplier_id' => $this->supplier_id,
            'source' => $this->source,
            'reference' => $this->reference,
            'reason' => $this->reason,
            'actor_id' => $this->actor_id,
            'actor_name' => $this->actor?->name,
        ];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'serials' => 'array',
            'expires_at' => 'date',
            'unit_cost' => 'decimal:2',
        ];
    }
}

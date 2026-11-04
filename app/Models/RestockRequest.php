<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer's request to hear when a sold-out reward is back in stock.
 */
#[Fillable(['customer_id', 'reward_id', 'variant_id', 'notified_at'])]
class RestockRequest extends Model
{
    /**
     * Get the reward the customer is waiting for.
     *
     * @return BelongsTo<Reward, $this>
     */
    public function reward(): BelongsTo
    {
        return $this->belongsTo(Reward::class);
    }

    /**
     * Get the customer who asked.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Shape the request for API responses.
     *
     * @return array{id: int, customer_id: int, reward_id: int, variant_id: int|null, created_at: string|null, notified_at: string|null}
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'reward_id' => $this->reward_id,
            'variant_id' => $this->variant_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'notified_at' => $this->notified_at?->toIso8601String(),
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
            'notified_at' => 'datetime',
        ];
    }
}

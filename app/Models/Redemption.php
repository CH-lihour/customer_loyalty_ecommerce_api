<?php

namespace App\Models;

use Database\Factories\RedemptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'customer_id', 'reward_id', 'variant_id', 'location_id', 'points', 'status', 'fulfillment',
    'reserved_until', 'fulfilled_at', 'batch', 'serial', 'closed_at', 'close_reason',
])]
class Redemption extends Model
{
    /** @use HasFactory<RedemptionFactory> */
    use HasFactory;

    public const RESERVED = 'RESERVED';

    public const BACKORDERED = 'BACKORDERED';

    public const FULFILLED = 'FULFILLED';

    public const CANCELLED = 'CANCELLED';

    public const EXPIRED = 'EXPIRED';

    public const RETURNED = 'RETURNED';

    /**
     * The statuses of a redemption that is still waiting to be fulfilled.
     *
     * @var list<string>
     */
    public const OPEN = [self::RESERVED, self::BACKORDERED];

    /**
     * The fulfillment value of a redemption that has not reached the customer yet.
     */
    public const NOT_FULFILLED = 'PENDING';

    public const SHIPPED = 'SHIPPED';

    public const PICKED_UP = 'PICKED_UP';

    /**
     * The relations `toApiArray` reads.
     *
     * @var list<string>
     */
    public const RELATIONS = ['reward:id,name', 'variant'];

    /**
     * Get the customer who redeemed the reward.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the redeemed reward.
     *
     * @return BelongsTo<Reward, $this>
     */
    public function reward(): BelongsTo
    {
        return $this->belongsTo(Reward::class);
    }

    /**
     * Get the chosen size or colour.
     *
     * @return BelongsTo<RewardVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(RewardVariant::class);
    }

    /**
     * Get the location holding the reserved unit.
     *
     * @return BelongsTo<StockLocation, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class);
    }

    /**
     * The redemption's number in the stock ledger, such as "RDM-000012".
     */
    public function reference(): string
    {
        return 'RDM-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * The reward's name, with the chosen variant when there is one.
     */
    public function rewardName(): string
    {
        $name = $this->reward?->name ?? 'Reward';

        if (! $this->variant) {
            return $name;
        }

        $label = collect([$this->variant->size, $this->variant->color])->filter()->implode(' / ') ?: $this->variant->sku;

        return "{$name} ({$label})";
    }

    /**
     * Shape the redemption for API responses.
     *
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'reward_id' => $this->reward_id,
            'reward_name' => $this->rewardName(),
            'variant_id' => $this->variant_id,
            'location_id' => $this->location_id,
            'points' => $this->points,
            'status' => $this->status,
            'fulfillment' => $this->fulfillment === self::NOT_FULFILLED ? null : $this->fulfillment,
            'created_at' => $this->created_at?->toIso8601String(),
            'reserved_until' => $this->reserved_until?->toIso8601String(),
            'fulfilled_at' => $this->fulfilled_at?->toIso8601String(),
            'batch' => $this->batch,
            'serial' => $this->serial,
            'closed_at' => $this->closed_at?->toIso8601String(),
            'close_reason' => $this->close_reason,
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
            'points' => 'integer',
            'reserved_until' => 'datetime',
            'fulfilled_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }
}

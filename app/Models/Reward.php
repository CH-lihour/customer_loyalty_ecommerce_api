<?php

namespace App\Models;

use Database\Factories\RewardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['min_tier_id', 'name', 'name_kh', 'description', 'category', 'points_cost', 'stock', 'image', 'is_active', 'expires_at'])]
class Reward extends Model
{
    /** @use HasFactory<RewardFactory> */
    use HasFactory;

    /**
     * Get the lowest tier that can redeem this reward.
     *
     * @return BelongsTo<Tier, $this>
     */
    public function minTier(): BelongsTo
    {
        return $this->belongsTo(Tier::class, 'min_tier_id');
    }

    /**
     * Get the reward's stock settings.
     *
     * @return HasOne<RewardStockItem, $this>
     */
    public function stockItem(): HasOne
    {
        return $this->hasOne(RewardStockItem::class);
    }

    /**
     * Get the size and colour variants that hold stock separately.
     *
     * @return HasMany<RewardVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(RewardVariant::class)->orderBy('id');
    }

    /**
     * Get the reward's stock ledger.
     *
     * @return HasMany<RewardStockMovement, $this>
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(RewardStockMovement::class);
    }

    /**
     * The saved stock settings, or the defaults for a reward that has none yet.
     */
    public function stockSettings(): RewardStockItem
    {
        return $this->stockItem ?? RewardStockItem::defaultsFor($this);
    }

    /**
     * Every variant id that can hold stock; null stands for the standard item when there are no variants.
     *
     * @return list<int|null>
     */
    public function variantIds(): array
    {
        $ids = $this->variants->pluck('id')->all();

        return $ids ?: [null];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'expires_at' => 'datetime',
        ];
    }
}

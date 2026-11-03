<?php

namespace App\Models;

use Database\Factories\RewardStockItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['reward_id', 'supplier_id', 'sku', 'unit_cost', 'tracking', 'low_stock_threshold', 'reorder_point', 'reorder_qty', 'out_of_stock', 'allow_backorder'])]
class RewardStockItem extends Model
{
    /** @use HasFactory<RewardStockItemFactory> */
    use HasFactory;

    /**
     * How units are told apart: by quantity only, by batch with expiry, or by serial number.
     *
     * @var list<string>
     */
    public const TRACKING = ['none', 'batch', 'serial'];

    /**
     * Get the reward these settings belong to.
     *
     * @return BelongsTo<Reward, $this>
     */
    public function reward(): BelongsTo
    {
        return $this->belongsTo(Reward::class);
    }

    /**
     * Get the usual supplier or partner.
     *
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Unsaved settings for a reward that has none yet, matching the frontend's defaults.
     */
    public static function defaultsFor(Reward $reward): self
    {
        return new self([
            'reward_id' => $reward->id,
            'sku' => 'RW-'.$reward->id,
            'unit_cost' => 0,
            'tracking' => 'none',
            'low_stock_threshold' => 5,
            'reorder_point' => 10,
            'reorder_qty' => 25,
            'out_of_stock' => 'disable',
            'allow_backorder' => false,
        ]);
    }

    /**
     * Shape the settings for API responses.
     *
     * @return array{sku: string, unit_cost: float, supplier_id: int|null, tracking: string, low_stock_threshold: int, reorder_point: int, reorder_qty: int, out_of_stock: string, allow_backorder: bool}
     */
    public function toApiArray(): array
    {
        return [
            'sku' => $this->sku,
            'unit_cost' => (float) $this->unit_cost,
            'supplier_id' => $this->supplier_id,
            'tracking' => $this->tracking,
            'low_stock_threshold' => (int) $this->low_stock_threshold,
            'reorder_point' => (int) $this->reorder_point,
            'reorder_qty' => (int) $this->reorder_qty,
            'out_of_stock' => $this->out_of_stock,
            'allow_backorder' => (bool) $this->allow_backorder,
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
            'unit_cost' => 'decimal:2',
            'allow_backorder' => 'boolean',
        ];
    }
}

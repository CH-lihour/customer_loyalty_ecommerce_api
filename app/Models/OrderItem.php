<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product line of an order, with the price and points rules as they were when it was bought.
 */
#[Fillable(['order_id', 'product_id', 'quantity', 'unit_price', 'base_points', 'bonus_multiplier'])]
class OrderItem extends Model
{
    /**
     * Get the order the line belongs to.
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the product bought, including one deleted since.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'base_points' => 'integer',
            'bonus_multiplier' => 'decimal:2',
        ];
    }
}

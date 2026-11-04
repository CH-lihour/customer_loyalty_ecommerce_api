<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['product_id', 'warehouse_id', 'qty_on_hand', 'qty_reserved'])]
class Inventory extends Model
{
    /**
     * Get the product this stock is of.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Units that can still be sold.
     */
    public function available(): int
    {
        return $this->qty_on_hand - $this->qty_reserved;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'qty_on_hand' => 'integer',
            'qty_reserved' => 'integer',
        ];
    }
}

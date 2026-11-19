<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How an order was paid. `amount` is the order total in USD; `paid_amount` is the amount in the KHQR code,
 * in its own currency.
 */
#[Fillable(['order_id', 'method', 'reference', 'transaction_id', 'status', 'amount', 'currency', 'paid_amount', 'paid_at'])]
class Payment extends Model
{
    public const METHOD_KHQR = 'KHQR';

    public const STATUS_PAID = 'PAID';

    /**
     * Get the order the payment is for.
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }
}

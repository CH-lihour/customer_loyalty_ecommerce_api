<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['order_id', 'method', 'status', 'amount', 'paid_at'])]
class Payment extends Model
{
    public const CASH_ON_DELIVERY = 'CASH_ON_DELIVERY';

    public const PENDING = 'PENDING';

    public const PAID = 'PAID';

    public const CANCELLED = 'CANCELLED';

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

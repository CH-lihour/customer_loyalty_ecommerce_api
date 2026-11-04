<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['order_id', 'carrier', 'tracking_no', 'status', 'shipped_at'])]
class Shipment extends Model
{
    public const SHIPPED = 'SHIPPED';

    public const DELIVERED = 'DELIVERED';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'shipped_at' => 'datetime',
        ];
    }
}

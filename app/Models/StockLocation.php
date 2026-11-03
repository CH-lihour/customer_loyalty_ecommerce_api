<?php

namespace App\Models;

use Database\Factories\StockLocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'name_kh', 'type', 'is_active'])]
class StockLocation extends Model
{
    /** @use HasFactory<StockLocationFactory> */
    use HasFactory;

    /**
     * The kinds of place reward stock is held.
     *
     * @var list<string>
     */
    public const TYPES = ['warehouse', 'store', 'pickup'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}

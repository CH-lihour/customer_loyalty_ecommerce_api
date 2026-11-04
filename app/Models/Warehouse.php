<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'location', 'is_active'])]
class Warehouse extends Model
{
    /**
     * The name of the warehouse product stock is kept in.
     */
    public const MAIN = 'Main Warehouse';

    /**
     * The warehouse product stock is kept in, created the first time it is needed.
     */
    public static function main(): self
    {
        return self::query()->firstOrCreate(['name' => self::MAIN]);
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
        ];
    }
}

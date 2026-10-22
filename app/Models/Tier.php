<?php

namespace App\Models;

use Database\Factories\TierFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'min_points', 'max_points', 'multiplier', 'color'])]
class Tier extends Model
{
    /** @use HasFactory<TierFactory> */
    use HasFactory;

    /**
     * Get the customers in this tier.
     *
     * @return HasMany<Customer, $this>
     */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'multiplier' => 'decimal:2',
        ];
    }
}

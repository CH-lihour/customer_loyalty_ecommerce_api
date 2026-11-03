<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['stocktake_id', 'reward_id', 'variant_id', 'batch', 'serial', 'expected', 'counted', 'system_at_post'])]
class StocktakeLine extends Model
{
    /**
     * Get the stocktake this line belongs to.
     *
     * @return BelongsTo<Stocktake, $this>
     */
    public function stocktake(): BelongsTo
    {
        return $this->belongsTo(Stocktake::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expected' => 'integer',
            'counted' => 'integer',
            'system_at_post' => 'integer',
        ];
    }
}

<?php

namespace App\Models;

use Database\Factories\RewardVariantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['reward_id', 'size', 'color', 'sku'])]
class RewardVariant extends Model
{
    /** @use HasFactory<RewardVariantFactory> */
    use HasFactory;

    /**
     * Get the reward this variant belongs to.
     *
     * @return BelongsTo<Reward, $this>
     */
    public function reward(): BelongsTo
    {
        return $this->belongsTo(Reward::class);
    }

    /**
     * A short name for messages, such as "Standard / Red".
     */
    public function label(): string
    {
        return collect([$this->size, $this->color])->filter()->implode(' / ') ?: $this->sku;
    }
}

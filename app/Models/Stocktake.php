<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['location_id', 'created_by', 'posted_by', 'category', 'status', 'note', 'posted_at'])]
class Stocktake extends Model
{
    public const OPEN = 'OPEN';

    public const POSTED = 'POSTED';

    public const CANCELLED = 'CANCELLED';

    /**
     * Get the location being counted.
     *
     * @return BelongsTo<StockLocation, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'location_id');
    }

    /**
     * Get the count lines, in the order they were created.
     *
     * @return HasMany<StocktakeLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(StocktakeLine::class)->orderBy('id');
    }

    /**
     * Get the staff user who started the count.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the staff user who posted the count.
     *
     * @return BelongsTo<User, $this>
     */
    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /**
     * The reference written on the variance entries, such as "ST-000012".
     */
    public function reference(): string
    {
        return 'ST-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Shape the stocktake and its lines for API responses.
     *
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference(),
            'location_id' => $this->location_id,
            'category' => $this->category,
            'status' => $this->status,
            'note' => $this->note,
            'created_at' => $this->created_at?->toIso8601String(),
            'created_by' => $this->creator?->name,
            'posted_at' => $this->posted_at?->toIso8601String(),
            'posted_by' => $this->poster?->name,
            'lines' => $this->lines->map(fn (StocktakeLine $line) => $line->only([
                'id', 'reward_id', 'variant_id', 'batch', 'serial', 'expected', 'counted', 'system_at_post',
            ]))->values(),
        ];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'posted_at' => 'datetime',
        ];
    }
}

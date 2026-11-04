<?php

namespace App\Models;

use Database\Factories\BadgeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['code', 'name', 'name_kh', 'description', 'icon', 'color', 'metric', 'threshold'])]
class Badge extends Model
{
    /** @use HasFactory<BadgeFactory> */
    use HasFactory;

    /**
     * What a badge can count, matching the storefront's badge checks.
     */
    public const METRICS = ['orders', 'spend', 'referrals', 'qualifyingPoints', 'membershipDays'];

    /**
     * The icons the storefront can draw for a badge.
     */
    public const ICONS = ['medal', 'star', 'trophy', 'gift', 'cart', 'heart', 'gem', 'users', 'rocket'];

    /**
     * Get the customers who have earned the badge.
     *
     * @return BelongsToMany<Customer, $this>
     */
    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'customer_badges')->withPivot('awarded_at')->withTimestamps();
    }
}

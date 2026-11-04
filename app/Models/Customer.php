<?php

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['tier_id', 'tier_locked', 'name', 'name_kh', 'email', 'phone', 'password', 'province', 'avatar', 'referral_code'])]
#[Hidden(['password'])]
class Customer extends Authenticatable
{
    /** @use HasFactory<CustomerFactory> */
    use HasApiTokens, HasFactory;

    /**
     * Get the loyalty tier the customer belongs to.
     *
     * @return BelongsTo<Tier, $this>
     */
    public function tier(): BelongsTo
    {
        return $this->belongsTo(Tier::class);
    }

    /**
     * Get the customer's points ledger.
     *
     * @return HasMany<LoyaltyTransaction, $this>
     */
    public function loyaltyTransactions(): HasMany
    {
        return $this->hasMany(LoyaltyTransaction::class);
    }

    /**
     * Get the customer's delivery addresses.
     *
     * @return HasMany<Address, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    /**
     * Get the customer's orders.
     *
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Get the customer's redemptions.
     *
     * @return HasMany<Redemption, $this>
     */
    public function redemptions(): HasMany
    {
        return $this->hasMany(Redemption::class);
    }

    /**
     * Get the customer's requests to hear when a reward is back in stock.
     *
     * @return HasMany<RestockRequest, $this>
     */
    public function restockRequests(): HasMany
    {
        return $this->hasMany(RestockRequest::class);
    }

    /**
     * Get the referrals the customer made.
     *
     * @return HasMany<Referral, $this>
     */
    public function referrals(): HasMany
    {
        return $this->hasMany(Referral::class, 'referrer_id');
    }

    /**
     * Get the customer's tier changes.
     *
     * @return HasMany<TierHistory, $this>
     */
    public function tierHistories(): HasMany
    {
        return $this->hasMany(TierHistory::class);
    }

    /**
     * Get the badges the customer has earned.
     *
     * @return BelongsToMany<Badge, $this>
     */
    public function badges(): BelongsToMany
    {
        return $this->belongsToMany(Badge::class, 'customer_badges')->withPivot('awarded_at')->withTimestamps();
    }

    /**
     * Load what `toApiArray` reads.
     *
     * @param  Builder<Customer>  $query
     */
    public function scopeWithTotals(Builder $query): void
    {
        $query->with(['tier:id,name', 'badges:id,code'])
            ->withCount(['orders as completed_orders' => fn (Builder $query) => $query->where('status', Order::COMPLETED)])
            ->withMax('orders as last_order_at', 'ordered_at');
    }

    /**
     * Shape the customer for API responses. Run the query with `withTotals` first.
     *
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            ...$this->only(['id', 'name', 'name_kh', 'email', 'phone', 'province', 'referral_code', 'points_balance', 'qualifying_points', 'is_active', 'tier_locked']),
            'avatar' => $this->avatar ? url('storage/'.$this->avatar) : null,
            'total_spent' => (float) $this->total_spent,
            'tier' => $this->tier?->name,
            'joined_at' => $this->created_at?->toDateString(),
            'last_order_at' => $this->last_order_at ? substr((string) $this->last_order_at, 0, 10) : null,
            'orders_count' => (int) $this->completed_orders,
            'badges' => $this->badges->pluck('code')->values()->all(),
        ];
    }

    /**
     * Change the points balance and add the matching ledger row, one at a time per customer.
     *
     * Points earned from orders count toward the customer's tier; every other type changes only the balance.
     *
     * @throws ValidationException when the change would take the balance below zero.
     */
    public function recordPoints(string $type, int $points, ?string $reason = null, ?int $createdBy = null, ?int $orderId = null): LoyaltyTransaction
    {
        return DB::transaction(function () use ($type, $points, $reason, $createdBy, $orderId): LoyaltyTransaction {
            $customer = self::query()->lockForUpdate()->findOrFail($this->id);
            $before = $customer->points_balance;
            $after = $before + $points;

            if ($after < 0) {
                throw ValidationException::withMessages([
                    'points' => "{$customer->name} only has ".number_format($before).' points, so this would make the balance negative.',
                ]);
            }

            $customer->points_balance = $after;

            if ($type === LoyaltyTransaction::TYPE_EARN) {
                $customer->qualifying_points += $points;
            }

            $customer->save();
            $this->setRawAttributes($customer->getAttributes(), true);

            return $customer->loyaltyTransactions()->create([
                'created_by' => $createdBy,
                'order_id' => $orderId,
                'type' => $type,
                'points' => $points,
                'balance_before' => $before,
                'balance_after' => $after,
                'reason' => $reason,
            ]);
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'total_spent' => 'decimal:2',
            'points_balance' => 'integer',
            'qualifying_points' => 'integer',
            'is_active' => 'boolean',
            'tier_locked' => 'boolean',
        ];
    }
}

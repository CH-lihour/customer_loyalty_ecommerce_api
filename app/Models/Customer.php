<?php

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['tier_id', 'name', 'name_kh', 'email', 'phone', 'password', 'province', 'avatar', 'referral_code'])]
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
     * Change the points balance and add the matching ledger row, one at a time per customer.
     *
     * Points earned from orders count toward the customer's tier; every other type changes only the balance.
     *
     * @throws ValidationException when the change would take the balance below zero.
     */
    public function recordPoints(string $type, int $points, ?string $reason = null, ?int $createdBy = null): LoyaltyTransaction
    {
        return DB::transaction(function () use ($type, $points, $reason, $createdBy): LoyaltyTransaction {
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
            'is_active' => 'boolean',
        ];
    }
}

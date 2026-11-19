<?php

namespace App\Models;

use App\Mail\RegistrationOtpMail;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\HasApiTokens;
use Throwable;

#[Fillable(['tier_id', 'name', 'name_kh', 'email', 'email_verified_at', 'phone', 'password', 'province', 'avatar', 'referral_code'])]
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
     * Get the customer's orders.
     *
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Get the referral made when this customer signed up with someone's code, if any.
     *
     * @return HasOne<Referral, $this>
     */
    public function referredBy(): HasOne
    {
        return $this->hasOne(Referral::class, 'referred_id');
    }

    /**
     * Get the referrals of customers who signed up with this customer's code.
     *
     * @return HasMany<Referral, $this>
     */
    public function referralsMade(): HasMany
    {
        return $this->hasMany(Referral::class, 'referrer_id');
    }

    /**
     * Get the email code waiting to be confirmed, if any.
     *
     * @return HasOne<CustomerOtp, $this>
     */
    public function otp(): HasOne
    {
        return $this->hasOne(CustomerOtp::class);
    }

    /**
     * Email the customer a fresh verification code, replacing any earlier one.
     *
     * @throws Throwable when the mail cannot be sent; no code is left pending then.
     */
    public function sendVerificationCode(): void
    {
        $code = CustomerOtp::issueFor($this);

        try {
            Mail::to($this->email)->send(new RegistrationOtpMail($this->name, $code));
        } catch (Throwable $exception) {
            // A code that never arrived should not hold up the resend cooldown.
            $this->otp()->delete();

            throw $exception;
        }
    }

    /**
     * Change the points balance and add the matching ledger row, one at a time per customer.
     *
     * Points earned from orders count toward the customer's tier, and move the customer up to the highest tier
     * their qualifying points reach. Every other type changes only the balance.
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
                $customer->moveUpTier($reason);
            }

            $customer->save();
            $this->setRawAttributes($customer->getAttributes(), true);

            return $customer->loyaltyTransactions()->create([
                'order_id' => $orderId,
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
     * Put the customer in the highest tier their qualifying points reach, if it is above their current one,
     * and record the move. Tiers are never lowered here: staff manage those changes.
     */
    private function moveUpTier(?string $reason): void
    {
        $current = Tier::find($this->tier_id);
        $reached = Tier::query()
            ->where('min_points', '<=', $this->qualifying_points)
            ->orderByDesc('min_points')
            ->first();

        if (! $reached || ($current && $reached->min_points <= $current->min_points)) {
            return;
        }

        $this->tier_id = $reached->id;

        if ($current) {
            DB::table('tier_histories')->insert([
                'customer_id' => $this->id,
                'from_tier_id' => $current->id,
                'to_tier_id' => $reached->id,
                'reason' => $reason,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('activity_logs')->insert([
            'customer_id' => $this->id,
            'event' => 'TIER_CHANGED',
            'description' => ($current?->name ?? 'No tier')." → {$reached->name}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'total_spent' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}

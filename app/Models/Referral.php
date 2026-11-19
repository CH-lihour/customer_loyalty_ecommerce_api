<?php

namespace App\Models;

use Database\Factories\ReferralFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

#[Fillable(['referrer_id', 'referred_id', 'is_completed', 'completed_at', 'completed_by'])]
class Referral extends Model
{
    /** @use HasFactory<ReferralFactory> */
    use HasFactory;

    /**
     * Points the customer who shared the code earns when the referral converts.
     */
    public const REFERRER_POINTS = 100;

    /**
     * Points the new customer earns when the referral converts.
     */
    public const REFERRED_POINTS = 50;

    /**
     * Get the customer who shared their referral code.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'referrer_id');
    }

    /**
     * Get the customer who signed up with the code.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function referred(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'referred_id');
    }

    /**
     * Get the staff user who marked the referral converted.
     *
     * @return BelongsTo<User, $this>
     */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * Mark the referral converted and give both customers their REFERRAL points, once.
     *
     * Staff do it by hand (`$staffId`), or the friend's first paid order does it (`$order`).
     *
     * @throws ValidationException when the referral was already converted.
     */
    public function complete(?int $staffId = null, ?Order $order = null): self
    {
        return DB::transaction(function () use ($staffId, $order): self {
            // Lock the row so two clicks cannot pay the bonus twice.
            $referral = self::query()->with(['referrer', 'referred'])->lockForUpdate()->findOrFail($this->id);

            if ($referral->is_completed) {
                throw ValidationException::withMessages([
                    'referral' => 'This referral was already marked converted.',
                ]);
            }

            $referral->forceFill([
                'is_completed' => true,
                'completed_at' => now(),
                'completed_by' => $staffId,
            ])->save();

            $referrer = $referral->referrer;
            $referred = $referral->referred;

            $referrerReason = $order
                ? "Referral bonus: {$referred->name} made their first order"
                : "Referral bonus: {$referred->name} joined with your code";
            $referredReason = $order
                ? "Welcome bonus: first order {$order->order_number} with {$referrer->name}'s referral code"
                : "Welcome bonus: joined with {$referrer->name}'s referral code";

            foreach ([
                [$referrer, self::REFERRER_POINTS, $referrerReason],
                [$referred, self::REFERRED_POINTS, $referredReason],
            ] as [$customer, $points, $reason]) {
                $customer->recordPoints(LoyaltyTransaction::TYPE_REFERRAL, $points, $reason, $staffId);

                DB::table('activity_logs')->insert([
                    'customer_id' => $customer->id,
                    'user_id' => $staffId,
                    'event' => 'REFERRAL_CONVERTED',
                    'description' => "+{$points} points for {$customer->name} from {$referrer->name} → {$referred->name} referral",
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $referral;
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
            'is_completed' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A reward a customer spent points on. Redeeming takes the points and one unit of the reward's stock and holds
 * it for the customer until staff ship it or hand it over. Cancelling, or a hold running out, puts both back.
 */
#[Fillable([
    'customer_id', 'reward_id', 'variant_id', 'location_id', 'points', 'status', 'fulfillment',
    'reserved_until', 'fulfilled_at', 'batch', 'serial', 'closed_at', 'close_reason',
])]
class Redemption extends Model
{
    /** Held for the customer, waiting to be shipped or picked up. */
    public const STATUS_RESERVED = 'RESERVED';

    public const STATUS_FULFILLED = 'FULFILLED';

    /** Cancelled by the customer or staff; points and stock were returned. */
    public const STATUS_CANCELLED = 'CANCELLED';

    /** The hold ran out before it was fulfilled; points and stock were returned. */
    public const STATUS_EXPIRED = 'EXPIRED';

    public const STATUSES = [self::STATUS_RESERVED, self::STATUS_FULFILLED, self::STATUS_CANCELLED, self::STATUS_EXPIRED];

    /** How a fulfilled redemption reached the customer; PENDING until then. */
    public const FULFILLMENT_PENDING = 'PENDING';

    public const FULFILLMENT_SHIPPED = 'SHIPPED';

    public const FULFILLMENT_PICKED_UP = 'PICKED_UP';

    public const FULFILLMENT_METHODS = [self::FULFILLMENT_SHIPPED, self::FULFILLMENT_PICKED_UP];

    /**
     * Get the customer who redeemed the reward.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the redeemed reward.
     *
     * @return BelongsTo<Reward, $this>
     */
    public function reward(): BelongsTo
    {
        return $this->belongsTo(Reward::class);
    }

    /**
     * Hours a redemption is held before it expires, unless staff extend it.
     */
    public static function holdHours(): int
    {
        return (int) config('services.redemptions.hold_hours', 72);
    }

    /**
     * Spend the customer's points on one unit of the reward and hold it for them.
     *
     * @throws ValidationException when the reward cannot be redeemed by this customer right now.
     */
    public static function redeem(Customer $customer, Reward $reward): self
    {
        return DB::transaction(function () use ($customer, $reward): self {
            $reward = Reward::query()->with('minTier')->lockForUpdate()->findOrFail($reward->id);
            $customer = Customer::query()->with('tier')->lockForUpdate()->findOrFail($customer->id);

            $fail = fn (string $message) => throw ValidationException::withMessages(['reward_id' => $message]);

            if (! $reward->is_active || ($reward->expires_at && $reward->expires_at->lt(today()))) {
                $fail("{$reward->name} is no longer available.");
            }
            if ($reward->minTier && (int) ($customer->tier?->min_points ?? 0) < (int) $reward->minTier->min_points) {
                $fail("{$reward->name} needs {$reward->minTier->name} tier or above.");
            }
            if ($reward->stock < 1) {
                $fail("{$reward->name} is out of stock.");
            }
            if ($customer->redemptions()->where('reward_id', $reward->id)->where('status', self::STATUS_RESERVED)->exists()) {
                $fail("You already redeemed {$reward->name}. It's waiting to be delivered.");
            }
            if ($customer->points_balance < $reward->points_cost) {
                $fail('You need '.number_format($reward->points_cost).' points for this reward but have '.number_format($customer->points_balance).'.');
            }

            $reward->decrement('stock');
            $customer->recordPoints(LoyaltyTransaction::TYPE_SPEND, -$reward->points_cost, "Redeemed: {$reward->name}");

            $redemption = $customer->redemptions()->create([
                'reward_id' => $reward->id,
                'points' => $reward->points_cost,
                'status' => self::STATUS_RESERVED,
                'fulfillment' => self::FULFILLMENT_PENDING,
                'reserved_until' => now()->addHours(self::holdHours()),
            ]);

            self::log($customer->id, null, 'REWARD_REDEEMED', "Redeemed {$reward->name} for {$reward->points_cost} points");

            return $redemption;
        });
    }

    /**
     * Mark a held redemption as shipped or picked up. The stock it took stays used.
     */
    public function fulfill(string $method, ?User $staff = null): self
    {
        return DB::transaction(function () use ($method, $staff): self {
            $redemption = self::query()->with('reward:id,name')->lockForUpdate()->findOrFail($this->id);
            $redemption->ensureReserved();

            $redemption->update([
                'status' => self::STATUS_FULFILLED,
                'fulfillment' => $method,
                'fulfilled_at' => now(),
            ]);

            $how = $method === self::FULFILLMENT_SHIPPED ? 'Shipped' : 'Handed over';
            self::log($redemption->customer_id, $staff?->id, 'REDEMPTION_FULFILLED', "{$how} {$redemption->reward?->name}");

            return $redemption;
        });
    }

    /**
     * Close a held redemption without fulfilling it, returning its stock and refunding its points.
     *
     * @param  string  $status  CANCELLED or EXPIRED
     */
    public function release(string $status, string $reason, ?User $staff = null): self
    {
        return DB::transaction(function () use ($status, $reason, $staff): self {
            $redemption = self::query()->with(['reward', 'customer'])->lockForUpdate()->findOrFail($this->id);
            $redemption->ensureReserved();

            $redemption->reward?->increment('stock');
            $name = $redemption->reward?->name ?? 'reward';
            $redemption->customer->recordPoints(
                LoyaltyTransaction::TYPE_REFUND,
                $redemption->points,
                ($status === self::STATUS_EXPIRED ? 'Hold expired' : 'Cancelled').": {$name}",
                $staff?->id,
            );

            $redemption->update([
                'status' => $status,
                'closed_at' => now(),
                'close_reason' => $reason,
            ]);

            self::log($redemption->customer_id, $staff?->id, 'REDEMPTION_'.$status, "{$name}: {$redemption->points} points refunded ({$reason})");

            return $redemption;
        });
    }

    /**
     * Give a held redemption more time before it expires.
     */
    public function extend(int $hours, ?User $staff = null): self
    {
        return DB::transaction(function () use ($hours, $staff): self {
            $redemption = self::query()->with('reward:id,name')->lockForUpdate()->findOrFail($this->id);
            $redemption->ensureReserved();

            $redemption->update(['reserved_until' => $redemption->reserved_until->copy()->max(now())->addHours($hours)]);
            self::log($redemption->customer_id, $staff?->id, 'REDEMPTION_EXTENDED', "Hold on {$redemption->reward?->name} extended by {$hours}h");

            return $redemption;
        });
    }

    /**
     * Expire every hold that has run out, refunding its points. Called before redemptions are listed, so no
     * scheduler is needed for the demo.
     */
    public static function expireOverdue(): void
    {
        self::query()
            ->where('status', self::STATUS_RESERVED)
            ->where('reserved_until', '<', now())
            ->get()
            ->each(fn (self $redemption) => $redemption->release(self::STATUS_EXPIRED, 'Not collected in time'));
    }

    private function ensureReserved(): void
    {
        if ($this->status !== self::STATUS_RESERVED) {
            throw ValidationException::withMessages([
                'status' => 'This redemption is already '.strtolower($this->status).'.',
            ]);
        }
    }

    private static function log(int $customerId, ?int $userId, string $event, string $description): void
    {
        DB::table('activity_logs')->insert([
            'customer_id' => $customerId,
            'user_id' => $userId,
            'event' => $event,
            'description' => $description,
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
            'points' => 'integer',
            'reserved_until' => 'datetime',
            'fulfilled_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }
}

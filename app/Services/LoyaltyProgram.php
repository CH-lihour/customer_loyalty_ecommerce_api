<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Badge;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Referral;
use App\Models\Tier;
use App\Models\TierHistory;
use App\Models\User;

/**
 * What a completed order earns: points, tier changes, the referral bonus and badges.
 * The rules match the ones the frontend's demo store used.
 */
class LoyaltyProgram
{
    /**
     * Points the referrer earns when the customer they referred completes a first order.
     */
    public const REFERRER_BONUS = 100;

    /**
     * Points a referred customer earns for completing their first order.
     */
    public const REFERRED_BONUS = 50;

    /**
     * The points an order earns: each item's points and bonus, scaled by the customer's tier and the best live campaign.
     */
    public function pointsFor(Order $order, Customer $customer): int
    {
        $base = $order->items->sum(fn (OrderItem $item): float => $item->base_points * (float) $item->bonus_multiplier * $item->quantity);
        $tier = (float) ($customer->tier?->multiplier ?? 1);
        $campaign = max(1, (float) Campaign::query()->live()->max('multiplier'));

        // Rounding first keeps floating point noise from losing a point.
        return (int) floor(round($base * $tier * $campaign, 6));
    }

    /**
     * Give the customer everything a completed order earns. Runs inside the order's transaction.
     */
    public function orderCompleted(Order $order): void
    {
        $customer = Customer::query()->with('tier')->findOrFail($order->customer_id);
        $reference = $order->reference();
        $isFirstOrder = ! $customer->orders()->where('status', Order::COMPLETED)->whereKeyNot($order->id)->exists();
        $earned = $this->pointsFor($order, $customer);

        $order->update(['points_earned' => $earned]);
        $customer->increment('total_spent', (float) $order->total_amount);

        if ($earned > 0) {
            $customer->recordPoints(LoyaltyTransaction::TYPE_EARN, $earned, "Completed order {$reference}", orderId: $order->id);
            ActivityLog::record('POINTS_EARNED', "Earned {$earned} points from {$reference}", $customer);
        }

        $this->refreshTier($customer, "Completed order {$reference}");

        if ($isFirstOrder) {
            $this->convertReferral($customer);
        }

        $this->awardBadges($customer);
    }

    /**
     * Move the customer to the tier their qualifying points reach, unless staff locked it.
     */
    public function refreshTier(Customer $customer, string $reason): void
    {
        if ($customer->tier_locked) {
            return;
        }

        $tier = Tier::query()->where('min_points', '<=', $customer->qualifying_points)->orderByDesc('min_points')->first();

        if ($tier) {
            $this->moveTier($customer, $tier, $reason);
        }
    }

    /**
     * Lock the customer to a tier, or unlock them with null so their points decide it again.
     */
    public function overrideTier(Customer $customer, ?Tier $tier, string $reason, User $staff): void
    {
        $customer->update(['tier_locked' => $tier !== null]);

        if ($tier) {
            $this->moveTier($customer, $tier, $reason, $staff);
        } else {
            $this->refreshTier($customer, $reason);
        }
    }

    /**
     * Give the customer every badge they now qualify for and have not earned yet.
     */
    public function awardBadges(Customer $customer): void
    {
        $stats = [
            'orders' => $customer->orders()->where('status', Order::COMPLETED)->count(),
            'spend' => (float) $customer->total_spent,
            'referrals' => Referral::query()->where('referrer_id', $customer->id)->where('is_completed', true)->count(),
            'qualifyingPoints' => (int) $customer->qualifying_points,
            'membershipDays' => (int) $customer->created_at?->diffInDays(now()),
        ];

        Badge::query()
            ->whereNotIn('id', $customer->badges()->pluck('badges.id'))
            ->whereNotNull('metric')
            ->get()
            ->filter(fn (Badge $badge): bool => $badge->threshold !== null && ($stats[$badge->metric] ?? 0) >= $badge->threshold)
            ->each(function (Badge $badge) use ($customer): void {
                $customer->badges()->attach($badge->id);
                ActivityLog::record('BADGE_AWARDED', "Earned badge: {$badge->name}", $customer);
            });
    }

    /**
     * Pay the referral bonus to both customers when a referred customer completes their first order.
     */
    private function convertReferral(Customer $customer): void
    {
        $referral = Referral::query()->with('referrer')->where('referred_id', $customer->id)->where('is_completed', false)->first();

        if (! $referral || $referral->referrer_id === $customer->id) {
            return;
        }

        $referral->update(['is_completed' => true]);

        foreach ([[$referral->referrer, self::REFERRER_BONUS], [$customer, self::REFERRED_BONUS]] as [$recipient, $bonus]) {
            $recipient->recordPoints(LoyaltyTransaction::TYPE_REFERRAL, $bonus, "Referral conversion for {$customer->name}");
            ActivityLog::record('REFERRAL_CONVERTED', "Earned {$bonus} points from {$customer->name}'s first order", $recipient);
        }

        $this->awardBadges($referral->referrer);
    }

    /**
     * Change the customer's tier and add it to their tier history.
     */
    private function moveTier(Customer $customer, Tier $tier, string $reason, ?User $staff = null): void
    {
        if ($customer->tier_id === $tier->id) {
            return;
        }

        $from = $customer->tier;

        TierHistory::query()->create([
            'customer_id' => $customer->id,
            'from_tier_id' => $customer->tier_id,
            'to_tier_id' => $tier->id,
            'reason' => $reason,
        ]);

        $customer->update(['tier_id' => $tier->id]);
        $customer->setRelation('tier', $tier);

        ActivityLog::record('TIER_CHANGED', "{$from?->name} → {$tier->name}: {$reason}", $customer, $staff);
    }
}

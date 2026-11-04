<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\LoyaltyTransaction;
use App\Models\Redemption;
use App\Models\RestockRequest;
use App\Models\Reward;
use App\Models\RewardStockMovement;
use App\Models\Setting;
use App\Models\StockLocation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Spending points on rewards: a unit is reserved in the stock ledger until staff fulfil it,
 * the customer or staff cancel it, or the reservation runs out. Points and stock change together.
 */
class RedemptionService
{
    public function __construct(private StockLedger $ledger) {}

    /**
     * Spend the customer's points on a reward, reserving a unit from the location with the most,
     * or backordering it when the reward allows that.
     *
     * @throws ValidationException when the customer cannot redeem the reward.
     */
    public function redeem(Customer $customer, int $rewardId, ?int $variantId): Redemption
    {
        return DB::transaction(function () use ($customer, $rewardId, $variantId): Redemption {
            $reward = Reward::query()->with(['stockItem', 'variants', 'minTier'])->lockForUpdate()->find($rewardId);
            $customer = Customer::query()->with('tier')->lockForUpdate()->findOrFail($customer->id);

            if (! $reward?->is_active || $reward->expires_at?->isBefore(today())) {
                $this->fail('Reward unavailable.');
            }

            if (! in_array($variantId, $reward->variantIds(), true)) {
                $this->fail('Choose an option for this reward.');
            }

            $alreadyOpen = Redemption::query()
                ->where('customer_id', $customer->id)
                ->where('reward_id', $reward->id)
                ->whereIn('status', Redemption::OPEN)
                ->exists();

            if ($alreadyOpen) {
                $this->fail('You already redeemed this reward.');
            }

            if ($customer->points_balance < $reward->points_cost) {
                $this->fail('Insufficient points.');
            }

            if ($reward->minTier && ($customer->tier?->min_points ?? 0) < $reward->minTier->min_points) {
                $this->fail("Requires {$reward->minTier->name} tier.");
            }

            $locationId = $this->ledger->bestLocation($reward->id, $variantId);

            if (! $locationId && ! $reward->stockSettings()->allow_backorder) {
                $this->fail('Reward is out of stock.');
            }

            $redemption = Redemption::query()->create([
                'customer_id' => $customer->id,
                'reward_id' => $reward->id,
                'variant_id' => $variantId,
                'location_id' => $locationId,
                'points' => $reward->points_cost,
                'status' => $locationId ? Redemption::RESERVED : Redemption::BACKORDERED,
                'fulfillment' => Redemption::NOT_FULFILLED,
                'reserved_until' => $locationId ? $this->reservationEnd() : null,
            ])->load(Redemption::RELATIONS);
            $name = $redemption->rewardName();

            $customer->recordPoints(LoyaltyTransaction::TYPE_SPEND, -$reward->points_cost, "Redeemed: {$name}");

            if ($locationId) {
                $this->ledger->hold($redemption, 'RESERVE', 'Online redemption');
            }

            ActivityLog::record('REWARD_REDEEMED', "Redeemed {$name} for {$reward->points_cost} points".($locationId ? '' : ' (backordered)'), $customer);

            return $redemption;
        });
    }

    /**
     * Close an open redemption, releasing its unit and refunding its points.
     *
     * @throws ValidationException when the redemption is no longer open.
     */
    public function cancel(Redemption $redemption, string $reason, ?User $staff = null): Redemption
    {
        return DB::transaction(function () use ($redemption, $reason, $staff): Redemption {
            $redemption = $this->lock($redemption);

            if (! in_array($redemption->status, Redemption::OPEN, true)) {
                $this->fail('Only reserved or backordered redemptions can be cancelled.');
            }

            $this->close($redemption, Redemption::CANCELLED, $reason, $staff);
            $this->settle();

            if ($staff) {
                ActivityLog::record('REDEMPTION_CANCELLED', "Cancelled {$redemption->rewardName()}: {$reason}", $redemption->customer, $staff);
            }

            return $redemption->refresh()->load(Redemption::RELATIONS);
        });
    }

    /**
     * Hand a reserved unit to the customer, taking it out of stock.
     *
     * @throws ValidationException when the redemption is not reserved or the unit cannot be found.
     */
    public function fulfill(Redemption $redemption, string $method, ?string $serial, User $staff): Redemption
    {
        return DB::transaction(function () use ($redemption, $method, $serial, $staff): Redemption {
            $redemption = $this->lock($redemption);

            if ($redemption->status !== Redemption::RESERVED || ! $redemption->location_id) {
                $this->fail('Only reserved redemptions can be fulfilled.');
            }

            $part = $this->ledger->fulfill($staff, $redemption, $method === Redemption::SHIPPED ? 'Shipped to customer' : 'Picked up by customer', $serial);

            $redemption->update([
                'status' => Redemption::FULFILLED,
                'fulfillment' => $method,
                'fulfilled_at' => now(),
                'batch' => $part['batch'] ?? null,
                'serial' => $part['serials'][0] ?? null,
            ]);

            ActivityLog::record(
                'REDEMPTION_FULFILLED',
                ($method === Redemption::SHIPPED ? 'Shipped ' : 'Handed over ')."{$redemption->rewardName()} to {$redemption->customer?->name}",
                $redemption->customer,
                $staff,
            );

            return $redemption->load(Redemption::RELATIONS);
        });
    }

    /**
     * Put a fulfilled redemption's unit back into stock, optionally refunding its points. The batch or serial is
     * the one it left with, unless it left before those were tracked.
     *
     * @param  array{redemption_id: int, location_id: int, reason: string, refund_points?: bool, batch?: string|null, serials?: list<string>|null}  $input
     * @return Collection<int, RewardStockMovement>
     *
     * @throws ValidationException when the redemption was not fulfilled, or a clerk asks for a refund.
     */
    public function returnToStock(User $actor, array $input): Collection
    {
        $redemption = $this->lock(Redemption::query()->findOrFail($input['redemption_id']));
        $refund = (bool) ($input['refund_points'] ?? false);

        if ($redemption->status !== Redemption::FULFILLED) {
            $this->fail('Only fulfilled redemptions can be returned.');
        }

        if ($refund && ! $actor->hasStockAuthority('manager')) {
            $this->fail('Refunding points needs stock manager authority.');
        }

        $movements = $this->ledger->return($actor, [
            ...$input,
            'reward_id' => $redemption->reward_id,
            'variant_id' => $redemption->variant_id,
            'quantity' => 1,
            'batch' => $redemption->batch ?? ($input['batch'] ?? null),
            'serials' => $redemption->serial ? [$redemption->serial] : ($input['serials'] ?? []),
            'reference' => $redemption->reference(),
        ]);

        if ($refund) {
            $this->refund($redemption, 'Returned to stock');
        }

        $redemption->update(['status' => Redemption::RETURNED, 'closed_at' => now(), 'close_reason' => $input['reason']]);

        ActivityLog::record('STOCK_RETURNED', "Returned {$redemption->rewardName()}: {$input['reason']}", $redemption->customer, $actor);

        return $movements;
    }

    /**
     * Give a reserved redemption more time, counted from now or from its current end, whichever is later.
     *
     * @throws ValidationException when the redemption is not reserved.
     */
    public function extend(Redemption $redemption, int $hours, User $staff): Redemption
    {
        return DB::transaction(function () use ($redemption, $hours, $staff): Redemption {
            $redemption = $this->lock($redemption);

            if ($redemption->status !== Redemption::RESERVED) {
                $this->fail('Only reserved redemptions can be extended.');
            }

            $from = $redemption->reserved_until?->isFuture() ? $redemption->reserved_until : now();
            $redemption->update(['reserved_until' => $from->copy()->addHours($hours)]);

            ActivityLog::record('RESERVATION_EXTENDED', "Extended {$redemption->rewardName()} reservation by {$hours}h", $redemption->customer, $staff);

            return $redemption->load(Redemption::RELATIONS);
        });
    }

    /**
     * Close reservations whose time ran out, refunding their points. Returns how many expired.
     */
    public function expireOverdue(): int
    {
        return DB::transaction(function (): int {
            $overdue = Redemption::query()
                ->with(['customer', ...Redemption::RELATIONS])
                ->where('status', Redemption::RESERVED)
                ->where('reserved_until', '<', now())
                ->lockForUpdate()
                ->get();

            $overdue->each(fn (Redemption $redemption) => $this->close($redemption, Redemption::EXPIRED, 'Reservation timed out'));

            if ($overdue->isNotEmpty()) {
                $this->settle();
            }

            return $overdue->count();
        });
    }

    /**
     * Give freed or newly received stock to backorders, oldest first, then flag the "notify me"
     * requests that can now be met. Runs after anything that adds stock.
     */
    public function settle(): void
    {
        Redemption::query()
            ->with(['customer', ...Redemption::RELATIONS])
            ->where('status', Redemption::BACKORDERED)
            ->orderBy('id')
            ->get()
            ->each(function (Redemption $redemption): void {
                $locationId = $this->ledger->bestLocation($redemption->reward_id, $redemption->variant_id);

                if (! $locationId) {
                    return;
                }

                $redemption->update([
                    'status' => Redemption::RESERVED,
                    'location_id' => $locationId,
                    'reserved_until' => $this->reservationEnd(),
                ]);
                $this->ledger->hold($redemption, 'RESERVE', 'Backorder allocated');

                $location = StockLocation::query()->whereKey($locationId)->value('name');
                ActivityLog::record('BACKORDER_RESERVED', "{$redemption->rewardName()} is back in stock and reserved at {$location}", $redemption->customer);
            });

        RestockRequest::query()
            ->with(['customer', 'reward'])
            ->whereNull('notified_at')
            ->get()
            ->filter(fn (RestockRequest $request): bool => (bool) $request->reward?->is_active && $this->ledger->bestLocation($request->reward_id, $request->variant_id))
            ->each(function (RestockRequest $request): void {
                $request->update(['notified_at' => now()]);
                ActivityLog::record('RESTOCK_NOTIFIED', "{$request->reward->name} is back in stock", $request->customer);
            });
    }

    /**
     * When a reservation made now runs out.
     */
    private function reservationEnd(): Carbon
    {
        return now()->addHours(Setting::integer(Setting::RESERVATION_HOURS));
    }

    /**
     * Release the unit, refund the points and mark the redemption closed.
     */
    private function close(Redemption $redemption, string $status, string $reason, ?User $staff = null): void
    {
        $name = $redemption->rewardName();

        if ($redemption->status === Redemption::RESERVED && $redemption->location_id) {
            $this->ledger->hold($redemption, 'RELEASE', $reason, $staff);
        }

        $this->refund($redemption, $reason);

        $redemption->update(['status' => $status, 'closed_at' => now(), 'close_reason' => $reason]);
    }

    /**
     * Give the customer the redemption's points back.
     */
    private function refund(Redemption $redemption, string $reason): void
    {
        $name = $redemption->rewardName();

        $redemption->customer->recordPoints(LoyaltyTransaction::TYPE_REFUND, $redemption->points, "Refund for {$name}: {$reason}");
        ActivityLog::record('POINTS_REFUNDED', "Refunded {$redemption->points} points for {$name} ({$reason})", $redemption->customer);
    }

    /**
     * Reload a redemption with a row lock, so two staff cannot act on it at once.
     */
    private function lock(Redemption $redemption): Redemption
    {
        return Redemption::query()->with(['customer', ...Redemption::RELATIONS])->lockForUpdate()->findOrFail($redemption->id);
    }

    /**
     * Reject the action with a message the frontend shows as is.
     */
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['redemption' => $message]);
    }
}

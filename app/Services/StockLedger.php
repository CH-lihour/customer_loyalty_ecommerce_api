<?php

namespace App\Services;

use App\Models\Redemption;
use App\Models\Reward;
use App\Models\RewardStockItem;
use App\Models\RewardStockMovement;
use App\Models\StockLocation;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The reward stock ledger. Levels are never stored: they are replayed from the append-only movements,
 * following the same rules as the frontend's `computeLevels`.
 *
 * @phpstan-type BatchLevel array{batch: string, on_hand: int, expires_at: string|null}
 * @phpstan-type StockLevel array{reward_id: int, variant_id: int|null, location_id: int, on_hand: int, reserved: int, expired: int, available: int, batches: list<BatchLevel>, serials: list<string>}
 * @phpstan-type StockPart array{qty: int, batch?: string|null, expires_at?: string|null, serials?: list<string>|null}
 * @phpstan-type StockLine array{reward_id: int, variant_id?: int|null, location_id: int, quantity: int, batch?: string|null, expires_at?: string|null, serials?: list<string>|null}
 */
class StockLedger
{
    /**
     * Replay the ledger into levels per reward, variant and location, keyed "reward|variant|location".
     *
     * @return Collection<string, StockLevel>
     */
    public function levels(?int $rewardId = null): Collection
    {
        $movements = RewardStockMovement::query()
            ->when($rewardId, fn ($query) => $query->where('reward_id', $rewardId))
            ->orderBy('id')
            ->get(['reward_id', 'variant_id', 'location_id', 'on_hand_delta', 'reserved_delta', 'batch', 'expires_at', 'serials']);

        $expiries = [];
        $levels = [];

        foreach ($movements as $movement) {
            // The first receipt of a batch sets its expiry.
            if ($movement->batch && $movement->expires_at) {
                $expiries["{$movement->reward_id}|{$movement->batch}"] ??= $movement->expires_at->toDateString();
            }

            $key = self::key($movement->reward_id, $movement->variant_id, $movement->location_id);
            $levels[$key] ??= [
                'reward_id' => $movement->reward_id,
                'variant_id' => $movement->variant_id,
                'location_id' => $movement->location_id,
                'on_hand' => 0,
                'reserved' => 0,
                'batches' => [],
                'serials' => [],
            ];
            $levels[$key]['on_hand'] += $movement->on_hand_delta;
            $levels[$key]['reserved'] += $movement->reserved_delta;

            if ($movement->on_hand_delta && $movement->batch) {
                $levels[$key]['batches'][$movement->batch] = ($levels[$key]['batches'][$movement->batch] ?? 0) + $movement->on_hand_delta;
            }

            foreach ($movement->serials ?? [] as $serial) {
                if ($movement->on_hand_delta > 0) {
                    $levels[$key]['serials'][$serial] = true;
                } elseif ($movement->on_hand_delta < 0) {
                    unset($levels[$key]['serials'][$serial]);
                }
            }
        }

        $today = now()->toDateString();

        return collect($levels)->map(function (array $level) use ($expiries, $today): array {
            $batches = collect($level['batches'])
                ->filter(fn (int $qty) => $qty !== 0)
                ->map(fn (int $qty, string $batch) => [
                    'batch' => $batch,
                    'on_hand' => $qty,
                    'expires_at' => $expiries["{$level['reward_id']}|{$batch}"] ?? null,
                ])
                ->sortBy([
                    fn (array $a, array $b) => ($a['expires_at'] ?? '9999-12-31') <=> ($b['expires_at'] ?? '9999-12-31'),
                    fn (array $a, array $b) => $a['batch'] <=> $b['batch'],
                ])
                ->values()
                ->all();
            $expired = collect($batches)->filter(fn (array $batch) => $batch['expires_at'] && $batch['expires_at'] < $today)->sum('on_hand');
            $serials = array_keys($level['serials']);
            sort($serials);

            return [
                ...$level,
                'batches' => $batches,
                'serials' => $serials,
                'expired' => $expired,
                'available' => max(0, $level['on_hand'] - $expired - $level['reserved']),
            ];
        });
    }

    /**
     * The units available per reward at active locations, keyed by reward id. Rewards with no stock are left out.
     * Matches the frontend's `availableFor`.
     *
     * @return Collection<int, int>
     */
    public function availableByReward(?int $rewardId = null): Collection
    {
        $activeLocationIds = StockLocation::where('is_active', true)->pluck('id');

        return $this->levels($rewardId)
            ->whereIn('location_id', $activeLocationIds)
            ->groupBy('reward_id')
            ->map(fn (Collection $levels): int => $levels->sum('available'));
    }

    /**
     * The units available for each reward and variant at active locations, keyed "reward|variant".
     *
     * @return Collection<string, int>
     */
    public function availability(): Collection
    {
        $activeLocationIds = StockLocation::where('is_active', true)->pluck('id');

        return $this->levels()
            ->whereIn('location_id', $activeLocationIds)
            ->groupBy(fn (array $level): string => "{$level['reward_id']}|{$level['variant_id']}")
            ->map(fn (Collection $levels): int => $levels->sum('available'));
    }

    /**
     * The active location with the most units available for a reward and variant, if any has some.
     */
    public function bestLocation(int $rewardId, ?int $variantId): ?int
    {
        $levels = $this->levels($rewardId);

        return StockLocation::where('is_active', true)->pluck('id')
            ->mapWithKeys(fn (int $id): array => [$id => $levels->get(self::key($rewardId, $variantId, $id))['available'] ?? 0])
            ->filter()
            ->sortDesc()
            ->keys()
            ->first();
    }

    /**
     * Reserve a unit for a redemption ("RESERVE") or let it go ("RELEASE"). On-hand stock does not change.
     */
    public function hold(Redemption $redemption, string $type, string $reason, ?User $actor = null): void
    {
        $reward = Reward::query()->with('stockItem')->findOrFail($redemption->reward_id);

        $this->record($actor, $reward->stockSettings(), [
            'type' => $type,
            'reward_id' => $redemption->reward_id,
            'variant_id' => $redemption->variant_id,
            'location_id' => $redemption->location_id,
            'reference' => $redemption->reference(),
            'reason' => $reason,
        ], [['qty' => 0]], 1, $type === 'RESERVE' ? 1 : -1);
    }

    /**
     * Take a redemption's reserved unit out of stock, returning the batch or serial it came from.
     *
     * @return StockPart
     */
    public function fulfill(User $actor, Redemption $redemption, string $reason, ?string $serial = null): array
    {
        $line = [
            'reward_id' => $redemption->reward_id,
            'variant_id' => $redemption->variant_id,
            'location_id' => $redemption->location_id,
            'quantity' => 1,
            'serials' => $serial ? [$serial] : [],
        ];
        [, $item] = $this->checkLine($line, needActiveLocation: false);
        $parts = $this->resolveOut($this->levelFor($line), $item, $line, 'reserved');

        $this->record($actor, $item, [
            'type' => 'FULFILL',
            'reward_id' => $redemption->reward_id,
            'variant_id' => $redemption->variant_id,
            'location_id' => $redemption->location_id,
            'reference' => $redemption->reference(),
            'reason' => $reason,
        ], $parts, -1, -1);

        return $parts[0];
    }

    /**
     * The level key for a reward, variant and location.
     */
    public static function key(int $rewardId, ?int $variantId, int $locationId): string
    {
        return "{$rewardId}|".($variantId ?? '')."|{$locationId}";
    }

    /**
     * The expiry date a batch was first received with, if any.
     */
    public function batchExpiry(int $rewardId, string $batch): ?string
    {
        return RewardStockMovement::query()
            ->where('reward_id', $rewardId)
            ->where('batch', $batch)
            ->whereNotNull('expires_at')
            ->orderBy('id')
            ->first()
            ?->expires_at->toDateString();
    }

    /**
     * Receive purchased or donated stock.
     *
     * @param  StockLine&array{source: string, supplier_id?: int|null, unit_cost: float|int|string, reference?: string|null, reason?: string|null}  $input
     * @return Collection<int, RewardStockMovement>
     */
    public function receive(User $actor, array $input): Collection
    {
        [$reward, $item] = $this->checkLine($input, needActiveLocation: true);
        $parts = $this->resolveIn($reward, $item, $input);

        return $this->record($actor, $item, [
            'type' => 'RECEIVE',
            'reward_id' => $reward->id,
            'variant_id' => $input['variant_id'] ?? null,
            'location_id' => $input['location_id'],
            'supplier_id' => $input['supplier_id'] ?? null,
            'source' => $input['source'],
            'unit_cost' => $input['unit_cost'],
            'reference' => $input['reference'] ?? null,
            'reason' => ($input['reason'] ?? null) ?: ($input['source'] === 'DONATION' ? 'Partner donation' : 'Purchase receipt'),
        ], $parts, 1);
    }

    /**
     * Correct the count up or down by hand. Stock out may only come from unreserved units.
     *
     * @param  StockLine&array{direction: string, reason: string}  $input
     * @return Collection<int, RewardStockMovement>
     */
    public function adjust(User $actor, array $input): Collection
    {
        $stockIn = $input['direction'] === 'in';
        [$reward, $item] = $this->checkLine($input, needActiveLocation: $stockIn);
        $parts = $stockIn
            ? $this->resolveIn($reward, $item, $input)
            : $this->resolveOut($this->levelFor($input), $item, $input, 'available');

        return $this->record($actor, $item, [
            'type' => 'ADJUST',
            'reward_id' => $reward->id,
            'variant_id' => $input['variant_id'] ?? null,
            'location_id' => $input['location_id'],
            'reason' => $input['reason'],
        ], $parts, $stockIn ? 1 : -1);
    }

    /**
     * Move unreserved stock to another active location, as a pair of entries sharing one reference.
     *
     * @param  StockLine&array{to_location_id: int, reason?: string|null}  $input
     * @return Collection<int, RewardStockMovement>
     */
    public function transfer(User $actor, array $input): Collection
    {
        [$reward, $item] = $this->checkLine($input, needActiveLocation: false);
        $destination = StockLocation::find($input['to_location_id']);

        if (! $destination?->is_active) {
            $this->fail('Choose an active destination.');
        }

        $parts = $this->resolveOut($this->levelFor($input), $item, $input, 'available');
        $base = [
            'reward_id' => $reward->id,
            'variant_id' => $input['variant_id'] ?? null,
            'reference' => 'TRF-'.Str::upper(Str::random(8)),
            'reason' => ($input['reason'] ?? null) ?: "Transfer to {$destination->name}",
        ];

        return $this->record($actor, $item, [...$base, 'type' => 'TRANSFER_OUT', 'location_id' => $input['location_id']], $parts, -1)
            ->concat($this->record($actor, $item, [...$base, 'type' => 'TRANSFER_IN', 'location_id' => $destination->id], $parts, 1));
    }

    /**
     * Write off damaged, lost or expired stock. Expiry write-offs may only take expired batches.
     *
     * @param  StockLine&array{kind: string, reason: string}  $input
     * @return Collection<int, RewardStockMovement>
     */
    public function writeOff(User $actor, array $input): Collection
    {
        [$reward, $item] = $this->checkLine($input, needActiveLocation: false);
        $parts = $this->resolveOut($this->levelFor($input), $item, $input, $input['kind'] === 'EXPIRY' ? 'expired' : 'available');

        return $this->record($actor, $item, [
            'type' => $input['kind'],
            'reward_id' => $reward->id,
            'variant_id' => $input['variant_id'] ?? null,
            'location_id' => $input['location_id'],
            'reason' => $input['reason'],
        ], $parts, -1);
    }

    /**
     * Put returned units back into stock at an active location.
     *
     * @param  StockLine&array{reason: string, reference?: string|null}  $input
     * @return Collection<int, RewardStockMovement>
     */
    public function return(User $actor, array $input): Collection
    {
        [$reward, $item] = $this->checkLine($input, needActiveLocation: true);
        $parts = $this->resolveIn($reward, $item, $input);

        return $this->record($actor, $item, [
            'type' => 'RETURN',
            'reward_id' => $reward->id,
            'variant_id' => $input['variant_id'] ?? null,
            'location_id' => $input['location_id'],
            'reference' => $input['reference'] ?? null,
            'reason' => $input['reason'],
        ], $parts, 1);
    }

    /**
     * Append ledger entries, one per batch or serial group. `$sign` sets the direction of the on-hand change.
     *
     * @param  array<string, mixed>  $base
     * @param  list<StockPart>  $parts
     * @return Collection<int, RewardStockMovement>
     */
    public function record(?User $actor, RewardStockItem $item, array $base, array $parts, int $sign, int $reservedDelta = 0): Collection
    {
        return collect($parts)->map(fn (array $part) => RewardStockMovement::create([
            'unit_cost' => $item->unit_cost ?? 0,
            ...$base,
            'actor_id' => $actor?->id,
            'on_hand_delta' => $sign * $part['qty'],
            'reserved_delta' => $reservedDelta,
            'batch' => $part['batch'] ?? null,
            'expires_at' => $part['expires_at'] ?? null,
            'serials' => $part['serials'] ?? null,
        ])->setRelation('actor', $actor));
    }

    /**
     * Check the reward, variant and location shared by every stock form, locking the reward so
     * concurrent movements for it are applied one at a time.
     *
     * @param  StockLine  $line
     * @return array{0: Reward, 1: RewardStockItem}
     */
    private function checkLine(array $line, bool $needActiveLocation): array
    {
        $reward = Reward::query()->with(['stockItem', 'variants'])->lockForUpdate()->findOrFail($line['reward_id']);

        if (! in_array($line['variant_id'] ?? null, $reward->variantIds(), true)) {
            $this->fail($reward->variants->isNotEmpty() ? 'Choose a variant.' : 'This item has no variants.', 'variant_id');
        }

        $location = StockLocation::findOrFail($line['location_id']);

        if ($needActiveLocation && ! $location->is_active) {
            $this->fail("{$location->name} is inactive.", 'location_id');
        }

        return [$reward, $reward->stockSettings()];
    }

    /**
     * The current level for a line's reward, variant and location.
     *
     * @param  StockLine  $line
     * @return StockLevel|null
     */
    private function levelFor(array $line): ?array
    {
        return $this->levels($line['reward_id'])->get(self::key($line['reward_id'], $line['variant_id'] ?? null, $line['location_id']));
    }

    /**
     * Work out the batch or serials for stock coming in.
     *
     * @param  StockLine  $line
     * @return list<StockPart>
     */
    private function resolveIn(Reward $reward, RewardStockItem $item, array $line): array
    {
        $qty = $line['quantity'];

        if ($item->tracking === 'batch') {
            $batch = Str::upper(trim($line['batch'] ?? ''));

            if ($batch === '') {
                $this->fail('Enter a batch or lot code.', 'batch');
            }

            $existing = $this->batchExpiry($reward->id, $batch);
            $expiresAt = $line['expires_at'] ?? null;

            if ($existing && $expiresAt && $expiresAt !== $existing) {
                $this->fail("Batch {$batch} already expires on {$existing}.", 'expires_at');
            }

            return [['qty' => $qty, 'batch' => $batch, 'expires_at' => $existing ? null : $expiresAt]];
        }

        if ($item->tracking === 'serial') {
            $serials = self::normalizeSerials($line['serials'] ?? []);

            if (count($serials) !== $qty) {
                $this->fail("Enter {$qty} unique serial ".Str::plural('number', $qty).' (one per unit).', 'serials');
            }

            $inStock = $this->levels($reward->id)->flatMap(fn (array $level) => $level['serials']);
            $clash = collect($serials)->first(fn (string $serial) => $inStock->contains($serial));

            if ($clash) {
                $this->fail("Serial {$clash} is already in stock.", 'serials');
            }

            return [['qty' => $qty, 'serials' => $serials]];
        }

        return [['qty' => $qty]];
    }

    /**
     * Work out the batches or serials for stock going out.
     * "available": unreserved, unexpired stock (or a chosen expired batch). "expired": expired batches only.
     * "reserved": a unit already held for a redemption, so reservations do not count against it.
     *
     * @param  StockLevel|null  $level
     * @param  StockLine  $line
     * @return list<StockPart>
     */
    private function resolveOut(?array $level, RewardStockItem $item, array $line, string $mode): array
    {
        if (! $level || $level['on_hand'] <= 0) {
            $this->fail('There is no stock of this item at that location.');
        }

        $qty = $line['quantity'];
        $today = now()->toDateString();
        $unreserved = fn () => "Only {$level['available']} unreserved ".Str::plural('unit', $level['available']).' available here.';

        if ($item->tracking === 'batch') {
            $chosen = Str::upper(trim($line['batch'] ?? ''));

            if ($chosen !== '') {
                $batch = collect($level['batches'])->firstWhere('batch', $chosen);

                if (! $batch || $batch['on_hand'] < $qty) {
                    $this->fail("Batch {$chosen} has only ".($batch['on_hand'] ?? 0).' on hand here.', 'batch');
                }

                $expired = $batch['expires_at'] && $batch['expires_at'] < $today;

                if ($mode === 'expired' && ! $expired) {
                    $this->fail("Batch {$chosen} has not expired.", 'batch');
                }

                if ($mode === 'reserved' && $expired) {
                    $this->fail("Batch {$chosen} has expired.", 'batch');
                }

                if ($mode === 'available' && ! $expired && $qty > $level['available']) {
                    $this->fail($unreserved());
                }

                return [['qty' => $qty, 'batch' => $chosen]];
            }

            if ($mode === 'available' && $qty > $level['available']) {
                $this->fail($unreserved());
            }

            return $this->pickBatches($level['batches'], $qty, $mode === 'expired', $today)
                ?? $this->fail($mode === 'expired' ? 'Not enough expired stock here.' : 'Not enough unexpired stock here.');
        }

        if ($mode === 'expired') {
            $this->fail('This item does not track expiry dates.');
        }

        $capacity = $mode === 'reserved' ? $level['on_hand'] - $level['expired'] : $level['available'];

        if ($qty > $capacity) {
            $this->fail("Only {$capacity} ".Str::plural('unit', $capacity).' available here.');
        }

        if ($item->tracking === 'serial') {
            $serials = self::normalizeSerials($line['serials'] ?? []);

            if (! $serials && $mode === 'reserved') {
                $serials = array_slice($level['serials'], 0, $qty);
            }

            if (count($serials) !== $qty) {
                $this->fail("Choose {$qty} serial ".Str::plural('number', $qty).'.', 'serials');
            }

            $missing = collect($serials)->first(fn (string $serial) => ! in_array($serial, $level['serials'], true));

            if ($missing) {
                $this->fail("Serial {$missing} is not in stock here.", 'serials');
            }

            return [['qty' => $qty, 'serials' => $serials]];
        }

        return [['qty' => $qty]];
    }

    /**
     * Choose batches first-expiry-first-out, from unexpired batches or only expired ones. Null when there is not enough.
     *
     * @param  list<BatchLevel>  $batches
     * @return list<StockPart>|null
     */
    private function pickBatches(array $batches, int $qty, bool $expiredOnly, string $today): ?array
    {
        $picks = [];
        $remaining = $qty;

        foreach ($batches as $batch) {
            $isExpired = $batch['expires_at'] && $batch['expires_at'] < $today;

            if ($isExpired !== $expiredOnly || $batch['on_hand'] <= 0) {
                continue;
            }

            $take = min($batch['on_hand'], $remaining);
            $picks[] = ['qty' => $take, 'batch' => $batch['batch']];
            $remaining -= $take;

            if ($remaining === 0) {
                return $picks;
            }
        }

        return null;
    }

    /**
     * Trim, upper-case and de-duplicate serial numbers.
     *
     * @param  array<int, mixed>  $serials
     * @return list<string>
     */
    public static function normalizeSerials(array $serials): array
    {
        return collect($serials)
            ->map(fn ($serial) => Str::upper(trim((string) $serial)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Reject the movement with a message the frontend shows as is.
     */
    private function fail(string $message, string $field = 'quantity'): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}

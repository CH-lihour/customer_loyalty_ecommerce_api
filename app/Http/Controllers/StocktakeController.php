<?php

namespace App\Http\Controllers;

use App\Models\Reward;
use App\Models\StockLocation;
use App\Models\Stocktake;
use App\Models\StocktakeLine;
use App\Services\StockLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StocktakeController extends Controller
{
    /**
     * Start a count at an active location, optionally for one reward category. Expected quantities are a snapshot
     * of the ledger: one line per batch or serial for tracked items, otherwise one per variant.
     */
    public function store(Request $request, StockLedger $ledger): JsonResponse
    {
        return $this->attempt('Could not start the stocktake.', function () use ($request, $ledger): JsonResponse {
            $input = $request->validate([
                'location_id' => ['required', 'integer', Rule::exists('stock_locations', 'id')],
                'category' => ['nullable', 'string', 'max:255'],
                'note' => ['nullable', 'string', 'max:1000'],
            ]);
            $location = StockLocation::findOrFail($input['location_id']);

            if (! $location->is_active) {
                return $this->rejected('Choose an active location.');
            }

            if (Stocktake::where('location_id', $location->id)->where('status', Stocktake::OPEN)->exists()) {
                return $this->rejected("{$location->name} already has an open stocktake.");
            }

            $levels = $ledger->levels()->where('location_id', $location->id);
            $lines = [];
            $rewards = Reward::query()
                ->with(['stockItem', 'variants'])
                ->when($input['category'] ?? null, fn ($query, string $category) => $query->where('category', $category))
                ->orderBy('id')
                ->get();

            foreach ($rewards as $reward) {
                $tracking = $reward->stockSettings()->tracking;

                foreach ($reward->variantIds() as $variantId) {
                    $level = $levels->get(StockLedger::key($reward->id, $variantId, $location->id));
                    $base = ['reward_id' => $reward->id, 'variant_id' => $variantId];

                    if ($tracking === 'batch') {
                        foreach ($level['batches'] ?? [] as $batch) {
                            $lines[] = [...$base, 'batch' => $batch['batch'], 'expected' => $batch['on_hand']];
                        }
                    } elseif ($tracking === 'serial') {
                        foreach ($level['serials'] ?? [] as $serial) {
                            $lines[] = [...$base, 'serial' => $serial, 'expected' => 1];
                        }
                    } elseif ($level || $reward->is_active) {
                        $lines[] = [...$base, 'expected' => $level['on_hand'] ?? 0];
                    }
                }
            }

            if (! $lines) {
                return $this->rejected('Nothing to count at this location for that category.');
            }

            $stocktake = DB::transaction(function () use ($request, $input, $location, $lines): Stocktake {
                $stocktake = Stocktake::create([
                    'location_id' => $location->id,
                    'created_by' => $request->user()->id,
                    'category' => ($input['category'] ?? null) ?: null,
                    'status' => Stocktake::OPEN,
                    'note' => trim($input['note'] ?? '') ?: null,
                ]);
                $stocktake->lines()->createMany($lines);

                return $stocktake;
            });

            return response()->json([
                'success' => true,
                'message' => 'Stocktake started successfully',
                'data' => $this->shaped($stocktake),
            ], 201);
        });
    }

    /**
     * Record or clear the counted quantity of one line. Serial lines are counted as 0 or 1.
     */
    public function count(Request $request, Stocktake $stocktake, StocktakeLine $line): JsonResponse
    {
        return $this->attempt('Could not record the count.', function () use ($request, $stocktake, $line): JsonResponse {
            if ($stocktake->status !== Stocktake::OPEN) {
                return $this->rejected('This stocktake is closed.');
            }

            $input = $request->validate(
                ['counted' => ['present', 'nullable', 'integer', 'min:0', $line->serial ? 'max:1' : 'max:100000']],
                ['counted.max' => $line->serial ? 'Serial lines are counted as 0 or 1.' : 'Enter a whole count of zero or more.'],
            );
            $line->update(['counted' => $input['counted']]);

            return response()->json([
                'success' => true,
                'message' => 'Count recorded successfully',
                'data' => $line->only(['id', 'reward_id', 'variant_id', 'batch', 'serial', 'expected', 'counted', 'system_at_post']),
            ]);
        });
    }

    /**
     * Post a fully counted stocktake: each line's difference from the current system quantity becomes a COUNT entry.
     */
    public function post(Request $request, Stocktake $stocktake, StockLedger $ledger): JsonResponse
    {
        return $this->attempt('Could not post the stocktake.', function () use ($request, $stocktake, $ledger): JsonResponse {
            if ($stocktake->status !== Stocktake::OPEN) {
                return $this->rejected('Only open stocktakes can be posted.');
            }

            $stocktake->load('lines');

            if ($stocktake->lines->contains(fn (StocktakeLine $line) => $line->counted === null)) {
                return $this->rejected('Count every line before posting.');
            }

            return DB::transaction(function () use ($request, $stocktake, $ledger): JsonResponse {
                $levels = $ledger->levels();
                $rewards = Reward::query()->with('stockItem')->whereIn('id', $stocktake->lines->pluck('reward_id'))->lockForUpdate()->get()->keyBy('id');
                $variances = [];

                foreach ($stocktake->lines as $line) {
                    $level = $levels->get(StockLedger::key($line->reward_id, $line->variant_id, $stocktake->location_id));
                    $system = match (true) {
                        (bool) $line->serial => in_array($line->serial, $level['serials'] ?? [], true) ? 1 : 0,
                        (bool) $line->batch => collect($level['batches'] ?? [])->firstWhere('batch', $line->batch)['on_hand'] ?? 0,
                        default => $level['on_hand'] ?? 0,
                    };
                    $variance = $line->counted - $system;

                    if ($line->serial && $variance > 0 && $levels->contains(fn (array $other) => $other['reward_id'] === $line->reward_id && in_array($line->serial, $other['serials'], true))) {
                        // Throwing rolls back the lines already updated.
                        throw ValidationException::withMessages(['lines' => "Serial {$line->serial} is recorded at another location. Transfer it instead."]);
                    }

                    $line->update(['system_at_post' => $system]);

                    if ($variance !== 0) {
                        $variances[] = [$line, $variance];
                    }
                }

                foreach ($variances as [$line, $variance]) {
                    $ledger->record($request->user(), $rewards[$line->reward_id]->stockSettings(), [
                        'type' => 'COUNT',
                        'reward_id' => $line->reward_id,
                        'variant_id' => $line->variant_id,
                        'location_id' => $stocktake->location_id,
                        'reference' => $stocktake->reference(),
                        'reason' => "Stocktake {$stocktake->reference()} variance",
                    ], [['qty' => abs($variance), 'batch' => $line->batch, 'serials' => $line->serial ? [$line->serial] : null]], $variance > 0 ? 1 : -1);
                }

                $stocktake->update(['status' => Stocktake::POSTED, 'posted_by' => $request->user()->id, 'posted_at' => now()]);

                return response()->json([
                    'success' => true,
                    'message' => 'Stocktake posted successfully',
                    'data' => $this->shaped($stocktake),
                ]);
            });
        });
    }

    /**
     * Cancel an open stocktake without changing stock.
     */
    public function cancel(Stocktake $stocktake): JsonResponse
    {
        return $this->attempt('Could not cancel the stocktake.', function () use ($stocktake): JsonResponse {
            if ($stocktake->status !== Stocktake::OPEN) {
                return $this->rejected('Only open stocktakes can be cancelled.');
            }

            $stocktake->update(['status' => Stocktake::CANCELLED]);

            return response()->json([
                'success' => true,
                'message' => 'Stocktake cancelled successfully',
                'data' => $this->shaped($stocktake),
            ]);
        });
    }

    /**
     * The stocktake with its lines and staff names, for responses.
     *
     * @return array<string, mixed>
     */
    private function shaped(Stocktake $stocktake): array
    {
        return $stocktake->refresh()->load(['lines', 'creator:id,name', 'poster:id,name'])->toApiArray();
    }

    /**
     * A 422 for a request the stocktake's state does not allow.
     */
    private function rejected(string $message): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], 422);
    }
}

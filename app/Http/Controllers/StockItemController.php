<?php

namespace App\Http\Controllers;

use App\Http\Requests\StockItemRequest;
use App\Models\Reward;
use App\Models\RewardVariant;
use App\Services\StockLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class StockItemController extends Controller
{
    /**
     * Save a reward's stock rules and variants. Tracking and variants that hold stock can only change once that stock is zero.
     */
    public function update(StockItemRequest $request, Reward $reward, StockLedger $ledger): JsonResponse
    {
        return $this->attempt('Could not save the stock rules.', function () use ($request, $reward, $ledger): JsonResponse {
            $reward->load(['stockItem', 'variants']);
            $settings = Arr::except($request->validated(), 'variants');
            $variants = $request->variantRows();
            $levels = $ledger->levels($reward->id);
            $holds = fn (?int $variantId) => $levels->contains(fn (array $level) => $level['variant_id'] === $variantId && ($level['on_hand'] !== 0 || $level['reserved'] !== 0));

            $keptIds = $variants->pluck('id')->filter()->all();
            $kept = $variants->isEmpty() ? [null] : $keptIds;
            $dropped = collect($reward->variantIds())->first(fn (?int $id) => ! in_array($id, $kept, true) && $holds($id), false);

            if ($dropped !== false) {
                $label = $dropped === null ? 'Standard' : $reward->variants->firstWhere('id', $dropped)->label();

                return response()->json([
                    'success' => false,
                    'message' => "{$label} still has stock. Move it to zero first.",
                ], 422);
            }

            if ($settings['tracking'] !== $reward->stockSettings()->tracking && $levels->contains(fn (array $level) => $level['on_hand'] !== 0 || $level['reserved'] !== 0)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tracking can only change while the item has no stock on hand or reserved.',
                ], 422);
            }

            DB::transaction(function () use ($reward, $settings, $variants, $keptIds): void {
                $reward->stockItem()->updateOrCreate(['reward_id' => $reward->id], $settings);
                RewardVariant::where('reward_id', $reward->id)->whereNotIn('id', $keptIds)->delete();

                foreach ($variants as $variant) {
                    $fields = Arr::only($variant, ['size', 'color', 'sku']);
                    $variant['id']
                        ? RewardVariant::whereKey($variant['id'])->update($fields)
                        : $reward->variants()->create($fields);
                }
            });

            $reward->refresh()->load(['stockItem', 'variants']);

            return response()->json([
                'success' => true,
                'message' => 'Stock rules saved successfully',
                'data' => [
                    'reward_id' => $reward->id,
                    'stock_item' => $reward->stockSettings()->toApiArray(),
                    'variants' => $reward->variants->map(fn (RewardVariant $variant) => $variant->only(['id', 'size', 'color', 'sku']))->values(),
                ],
            ]);
        });
    }
}

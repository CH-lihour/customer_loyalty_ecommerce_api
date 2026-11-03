<?php

namespace App\Http\Controllers;

use App\Http\Requests\StockLocationRequest;
use App\Models\StockLocation;
use App\Services\StockLedger;
use Illuminate\Http\JsonResponse;

class StockLocationController extends Controller
{
    /**
     * Create a stock location.
     */
    public function store(StockLocationRequest $request): JsonResponse
    {
        return $this->attempt('Could not create the location.', fn (): JsonResponse => response()->json([
            'success' => true,
            'message' => 'Location created successfully',
            'data' => StockLocation::create($request->validated())->refresh(),
        ], 201));
    }

    /**
     * Update a stock location. A location can only be deactivated once it holds no stock, and one must stay active.
     */
    public function update(StockLocationRequest $request, StockLocation $stockLocation, StockLedger $ledger): JsonResponse
    {
        return $this->attempt('Could not update the location.', function () use ($request, $stockLocation, $ledger): JsonResponse {
            $fields = $request->validated();

            if ($stockLocation->is_active && ($fields['is_active'] ?? true) === false) {
                $held = $ledger->levels()->where('location_id', $stockLocation->id)
                    ->contains(fn (array $level) => $level['on_hand'] !== 0 || $level['reserved'] !== 0);

                if ($held) {
                    return response()->json([
                        'success' => false,
                        'message' => "Transfer or write off {$stockLocation->name}'s stock before deactivating it.",
                    ], 422);
                }

                if (StockLocation::where('is_active', true)->count() < 2) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Keep at least one active location.',
                    ], 422);
                }
            }

            $stockLocation->update($fields);

            return response()->json([
                'success' => true,
                'message' => 'Location updated successfully',
                'data' => $stockLocation,
            ]);
        });
    }
}

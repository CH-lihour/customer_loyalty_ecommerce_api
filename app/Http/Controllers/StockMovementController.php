<?php

namespace App\Http\Controllers;

use App\Http\Requests\StockMovementRequest;
use App\Models\RewardStockMovement;
use App\Services\StockLedger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StockMovementController extends Controller
{
    /**
     * List a page of the stock ledger, newest first, optionally filtered by date range, type, location, reward or staff user.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->attempt('Could not load stock movements.', function () use ($request): JsonResponse {
            $filters = $request->validate([
                'from' => ['nullable', 'date_format:Y-m-d'],
                'to' => ['nullable', 'date_format:Y-m-d'],
                'type' => ['nullable', Rule::in(RewardStockMovement::TYPES)],
                'location_id' => ['nullable', 'integer'],
                'reward_id' => ['nullable', 'integer'],
                'actor_id' => ['nullable', 'integer'],
            ]);
            $perPage = $this->perPage($request);

            $movements = $this->filtered($filters)->with('actor:id,name')->latest('id')->paginate($perPage);

            // The page shows the net on-hand change of every matching entry, not just this page.
            $response = $this->paginatedResponse($movements, 'Stock movements retrieved successfully', fn (RewardStockMovement $movement) => $movement->toApiArray());

            return $response->setData([...$response->getData(true), 'net_on_hand' => (int) $this->filtered($filters)->sum('on_hand_delta')]);
        });
    }

    /**
     * Ledger entries matching the list filters.
     *
     * @param  array{from?: string|null, to?: string|null, type?: string|null, location_id?: int|null, reward_id?: int|null, actor_id?: int|null}  $filters
     * @return Builder<RewardStockMovement>
     */
    private function filtered(array $filters): Builder
    {
        return RewardStockMovement::query()
            ->when($filters['from'] ?? null, fn ($query, string $from) => $query->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, string $to) => $query->whereDate('created_at', '<=', $to))
            ->when($filters['type'] ?? null, fn ($query, string $type) => $query->where('type', $type))
            ->when($filters['location_id'] ?? null, fn ($query, $id) => $query->where('location_id', $id))
            ->when($filters['reward_id'] ?? null, fn ($query, $id) => $query->where('reward_id', $id))
            ->when($filters['actor_id'] ?? null, fn ($query, $id) => $query->where('actor_id', $id));
    }

    /**
     * Record a receive, transfer, return, adjustment or write-off. Adjustments and write-offs need stock manager authority.
     */
    public function store(StockMovementRequest $request, StockLedger $ledger): JsonResponse
    {
        return $this->attempt('Could not record the stock movement.', function () use ($request, $ledger): JsonResponse {
            $input = $request->validated();
            Gate::authorize('manage-stock', StockMovementRequest::AUTHORITY[$input['type']]);
            $actor = $request->user();

            $movements = DB::transaction(fn () => match ($input['type']) {
                'receive' => $ledger->receive($actor, $input),
                'adjust' => $ledger->adjust($actor, $input),
                'transfer' => $ledger->transfer($actor, $input),
                'writeoff' => $ledger->writeOff($actor, $input),
                'return' => $ledger->return($actor, $input),
            });

            return response()->json([
                'success' => true,
                'message' => 'Stock movement recorded successfully',
                'data' => $movements->map(fn (RewardStockMovement $movement) => $movement->toApiArray())->values(),
            ], 201);
        });
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Requests\PointAdjustmentRequest;
use App\Models\Customer;
use App\Models\LoyaltyTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PointController extends Controller
{
    /**
     * List a page of the points ledger, newest first, optionally filtered by customer, type, direction or dates.
     */
    public function transactions(Request $request): JsonResponse
    {
        return $this->attempt('Could not load the points history.', function () use ($request): JsonResponse {
            $filters = $request->validate([
                'search' => ['nullable', 'string', 'max:255'],
                'type' => ['nullable', Rule::in(LoyaltyTransaction::TYPES)],
                'direction' => ['nullable', Rule::in(['earn', 'deduct'])],
                'date_from' => ['nullable', 'date'],
                'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            ]);

            $transactions = LoyaltyTransaction::query()
                ->with(['customer:id,name,email', 'creator:id,name'])
                ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                    $query->whereHas('customer', fn (Builder $query) => $this->search($query, $search));
                })
                ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('type', $type))
                ->when($filters['direction'] ?? null, fn (Builder $query, string $direction) => $query->where('points', $direction === 'earn' ? '>' : '<', 0))
                ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('created_at', '>=', $date))
                ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('created_at', '<=', $date))
                ->latest()
                ->orderByDesc('id')
                ->paginate($this->perPage($request));

            return $this->paginatedResponse($transactions, 'Points history retrieved successfully');
        });
    }

    /**
     * List a page of customers for the adjustment form, with their balance and tier.
     */
    public function customers(Request $request): JsonResponse
    {
        return $this->attempt('Could not load customers.', function () use ($request): JsonResponse {
            $filters = $request->validate(['search' => ['nullable', 'string', 'max:255']]);

            $customers = Customer::query()
                ->select(['id', 'tier_id', 'name', 'email', 'points_balance'])
                ->with('tier:id,name')
                ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $this->search($query, $search))
                ->orderBy('name')
                ->orderBy('id')
                ->paginate($this->perPage($request));

            return $this->paginatedResponse($customers, 'Customers retrieved successfully');
        });
    }

    /**
     * Manually add or remove a customer's points, recording who did it in the ledger and the activity log.
     *
     * The change affects the spendable balance only: tier progress comes from points earned on orders.
     */
    public function adjust(PointAdjustmentRequest $request): JsonResponse
    {
        return $this->attempt('Could not adjust the points.', function () use ($request): JsonResponse {
            $data = $request->validated();
            $staff = $request->user();

            $transaction = DB::transaction(function () use ($data, $staff): LoyaltyTransaction {
                $customer = Customer::findOrFail($data['customer_id']);
                $transaction = $customer->recordPoints(
                    LoyaltyTransaction::TYPE_ADJUST,
                    $data['points'],
                    "Manual adjustment: {$data['reason']}",
                    $staff->id,
                );

                DB::table('activity_logs')->insert([
                    'customer_id' => $customer->id,
                    'user_id' => $staff->id,
                    'event' => 'POINTS_ADJUSTED',
                    'description' => sprintf('%+d points for %s: %s', $data['points'], $customer->name, $data['reason']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return $transaction;
            });

            return response()->json([
                'success' => true,
                'message' => 'Points adjusted successfully',
                'data' => $transaction->load(['customer:id,name,email', 'creator:id,name']),
            ], 201);
        });
    }

    /**
     * List a page of the signed-in customer's own points history, newest first.
     */
    public function history(Request $request): JsonResponse
    {
        return $this->attempt('Could not load your points history.', function () use ($request): JsonResponse {
            $transactions = $request->user()->loyaltyTransactions()
                ->select(['id', 'customer_id', 'order_id', 'type', 'points', 'balance_before', 'balance_after', 'reason', 'created_at'])
                ->latest()
                ->orderByDesc('id')
                ->paginate($this->perPage($request));

            return $this->paginatedResponse($transactions, 'Points history retrieved successfully');
        });
    }

    /**
     * Match customers by id, name or email.
     *
     * @param  Builder<Customer>  $query
     */
    private function search(Builder $query, string $search): void
    {
        $term = '%'.mb_strtolower($search).'%';

        $query->where(function (Builder $query) use ($search, $term) {
            $query->whereRaw('lower(name) like ?', [$term])
                ->orWhereRaw('lower(email) like ?', [$term])
                ->when(ctype_digit($search), fn (Builder $query) => $query->orWhereKey((int) $search));
        });
    }
}

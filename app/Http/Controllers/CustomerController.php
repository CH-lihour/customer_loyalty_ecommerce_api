<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Tier;
use App\Services\LoyaltyProgram;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    /**
     * List a page of customers with their tier, badges and order totals, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->attempt('Could not load customers.', function () use ($request): JsonResponse {
            $filters = $request->validate(['search' => ['nullable', 'string', 'max:255']]);

            $customers = Customer::query()
                ->withTotals()
                ->when($filters['search'] ?? null, function ($query, string $search) {
                    $term = '%'.mb_strtolower($search).'%';
                    $query->where(fn ($query) => $query->whereRaw('lower(name) like ?', [$term])->orWhereRaw('lower(email) like ?', [$term]));
                })
                ->latest('id')
                ->paginate($this->perPage($request));

            return $this->paginatedResponse($customers, 'Customers retrieved successfully', fn (Customer $customer): array => $customer->toApiArray());
        });
    }

    /**
     * Lock a customer to a tier, or send a blank tier to let their points decide it again.
     */
    public function updateTier(Request $request, Customer $customer, LoyaltyProgram $loyalty): JsonResponse
    {
        return $this->attempt('Could not change the tier.', function () use ($request, $customer, $loyalty): JsonResponse {
            $data = $request->validate([
                'tier' => ['nullable', 'string', Rule::exists('tiers', 'name')],
                'reason' => ['required', 'string', 'max:255'],
            ]);
            $tier = isset($data['tier']) ? Tier::query()->where('name', $data['tier'])->firstOrFail() : null;

            $loyalty->overrideTier($customer, $tier, $data['reason'], $request->user());

            return response()->json([
                'success' => true,
                'message' => 'Tier updated successfully',
                'data' => Customer::query()->withTotals()->findOrFail($customer->id)->toApiArray(),
            ]);
        });
    }

    /**
     * Activate or deactivate a customer. Deactivating signs them out everywhere.
     */
    public function updateStatus(Request $request, Customer $customer): JsonResponse
    {
        return $this->attempt('Could not change the customer.', function () use ($request, $customer): JsonResponse {
            $data = $request->validate(['is_active' => ['required', 'boolean']]);

            $customer->forceFill(['is_active' => $data['is_active']])->save();

            if (! $customer->is_active) {
                $customer->tokens()->delete();
            }

            ActivityLog::record(
                'CUSTOMER_STATUS',
                ($customer->is_active ? 'Activated ' : 'Deactivated ').$customer->name,
                $customer,
                $request->user(),
            );

            return response()->json([
                'success' => true,
                'message' => $customer->is_active ? 'Customer activated' : 'Customer deactivated',
                'data' => Customer::query()->withTotals()->findOrFail($customer->id)->toApiArray(),
            ]);
        });
    }
}

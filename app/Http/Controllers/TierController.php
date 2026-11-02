<?php

namespace App\Http\Controllers;

use App\Http\Requests\TierRequest;
use App\Models\Tier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TierController extends Controller
{
    /**
     * List a page of tiers from the entry tier upwards, with how many customers each holds.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->attempt('Could not load tiers.', function () use ($request): JsonResponse {
            $tiers = Tier::query()
                ->withCount('customers')
                ->orderBy('min_points')
                ->orderBy('id')
                ->paginate($this->perPage($request));

            return $this->paginatedResponse($tiers, 'Tiers retrieved successfully');
        });
    }

    /**
     * Create a tier.
     */
    public function store(TierRequest $request): JsonResponse
    {
        return $this->attempt('Could not create the tier.', function () use ($request): JsonResponse {
            $tier = Tier::create($request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Tier created successfully',
                'data' => $tier->refresh()->loadCount('customers'),
            ], 201);
        });
    }

    /**
     * Show a tier.
     */
    public function show(Tier $tier): JsonResponse
    {
        return $this->attempt('Could not load the tier.', fn (): JsonResponse => response()->json([
            'success' => true,
            'message' => 'Tier retrieved successfully',
            'data' => $tier->loadCount('customers'),
        ]));
    }

    /**
     * Update a tier; PATCH changes only the fields sent.
     */
    public function update(TierRequest $request, Tier $tier): JsonResponse
    {
        return $this->attempt('Could not update the tier.', function () use ($request, $tier): JsonResponse {
            $tier->update($request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Tier updated successfully',
                'data' => $tier->loadCount('customers'),
            ]);
        });
    }

    /**
     * Delete a tier that no customer, reward or tier history still refers to, keeping at least one tier.
     */
    public function destroy(Tier $tier): JsonResponse
    {
        return $this->attempt('Could not delete the tier.', function () use ($tier): JsonResponse {
            if (Tier::count() <= 1) {
                return $this->refuse('Keep at least one tier: new customers join the lowest tier.');
            }

            $customerCount = $tier->customers()->count();

            if ($customerCount > 0) {
                return $this->refuse("Move the {$customerCount} ".str('customer')->plural($customerCount)." in {$tier->name} to another tier first.");
            }

            $rewardCount = DB::table('rewards')->where('min_tier_id', $tier->id)->count();

            if ($rewardCount > 0) {
                return $this->refuse("Change the minimum tier of the {$rewardCount} ".str('reward')->plural($rewardCount)." set to {$tier->name} first.");
            }

            $hasHistory = DB::table('tier_histories')
                ->where('from_tier_id', $tier->id)
                ->orWhere('to_tier_id', $tier->id)
                ->exists();

            if ($hasHistory) {
                return $this->refuse("{$tier->name} appears in customers' tier history, so it cannot be deleted.");
            }

            $tier->delete();

            return response()->json([
                'success' => true,
                'message' => 'Tier deleted successfully',
            ]);
        });
    }

    /**
     * Answer a delete that has to be refused.
     */
    private function refuse(string $message): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], 422);
    }
}

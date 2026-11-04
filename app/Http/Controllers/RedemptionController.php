<?php

namespace App\Http\Controllers;

use App\Models\Redemption;
use App\Services\RedemptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RedemptionController extends Controller
{
    /**
     * Redeem a reward for the signed-in customer.
     */
    public function store(Request $request, RedemptionService $redemptions): JsonResponse
    {
        return $this->attempt('Could not redeem the reward.', function () use ($request, $redemptions): JsonResponse {
            $data = $request->validate([
                'reward_id' => ['required', 'integer'],
                'variant_id' => ['nullable', 'integer'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Reward redeemed successfully',
                'data' => $redemptions->redeem($request->user(), $data['reward_id'], $data['variant_id'] ?? null)->toApiArray(),
            ], 201);
        });
    }

    /**
     * Cancel one of the signed-in customer's own redemptions.
     */
    public function cancelMine(Request $request, int $redemption, RedemptionService $redemptions): JsonResponse
    {
        return $this->attempt('Could not cancel the redemption.', function () use ($request, $redemption, $redemptions): JsonResponse {
            $own = $request->user()->redemptions()->findOrFail($redemption);

            return response()->json([
                'success' => true,
                'message' => 'Redemption cancelled',
                'data' => $redemptions->cancel($own, 'Cancelled by customer')->toApiArray(),
            ]);
        });
    }

    /**
     * Hand a reserved redemption to the customer.
     */
    public function fulfill(Request $request, Redemption $redemption, RedemptionService $redemptions): JsonResponse
    {
        return $this->attempt('Could not fulfil the redemption.', function () use ($request, $redemption, $redemptions): JsonResponse {
            $data = $request->validate([
                'method' => ['required', Rule::in([Redemption::SHIPPED, Redemption::PICKED_UP])],
                'serial' => ['nullable', 'string', 'max:255'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Redemption fulfilled',
                'data' => $redemptions->fulfill($redemption, $data['method'], $data['serial'] ?? null, $request->user())->toApiArray(),
            ]);
        });
    }

    /**
     * Cancel a redemption for a stated reason.
     */
    public function cancel(Request $request, Redemption $redemption, RedemptionService $redemptions): JsonResponse
    {
        return $this->attempt('Could not cancel the redemption.', function () use ($request, $redemption, $redemptions): JsonResponse {
            $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

            return response()->json([
                'success' => true,
                'message' => 'Redemption cancelled',
                'data' => $redemptions->cancel($redemption, $data['reason'], $request->user())->toApiArray(),
            ]);
        });
    }

    /**
     * Give a reservation more time.
     */
    public function extend(Request $request, Redemption $redemption, RedemptionService $redemptions): JsonResponse
    {
        return $this->attempt('Could not extend the reservation.', function () use ($request, $redemption, $redemptions): JsonResponse {
            $data = $request->validate(['hours' => ['required', 'integer', 'min:1', 'max:168']]);

            return response()->json([
                'success' => true,
                'message' => 'Reservation extended',
                'data' => $redemptions->extend($redemption, $data['hours'], $request->user())->toApiArray(),
            ]);
        });
    }
}

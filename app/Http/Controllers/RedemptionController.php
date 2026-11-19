<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Redemption;
use App\Models\Reward;
use App\Models\Tier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A customer's own reward redemptions: redeeming spends points and holds one unit of stock; cancelling a held
 * one refunds both.
 */
class RedemptionController extends Controller
{
    /**
     * Fields every redemption response includes from its reward.
     */
    public const RELATIONS = ['reward:id,name,name_kh,image,category,points_cost'];

    /**
     * List a page of the signed-in customer's redemptions, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->attempt('Could not load your redemptions.', function () use ($request): JsonResponse {
            Redemption::expireOverdue();

            $redemptions = $request->user()->redemptions()
                ->with(self::RELATIONS)
                ->latest()
                ->orderByDesc('id')
                ->paginate($this->perPage($request));

            return $this->paginatedResponse($redemptions, 'Redemptions retrieved successfully');
        });
    }

    /**
     * Redeem a reward with the customer's points.
     */
    public function store(Request $request): JsonResponse
    {
        return $this->attempt('Could not redeem the reward.', function () use ($request): JsonResponse {
            $data = $request->validate([
                'reward_id' => ['required', 'integer', Rule::exists('rewards', 'id')],
            ], [
                'reward_id.exists' => 'That reward is no longer available.',
            ]);

            /** @var Customer $customer */
            $customer = $request->user();
            $reward = Reward::findOrFail($data['reward_id']);
            $redemption = Redemption::redeem($customer, $reward);

            return response()->json([
                'success' => true,
                'message' => "Redeemed {$reward->name}. It's reserved for you.",
                'data' => $this->present($redemption, $customer->refresh()),
            ], 201);
        });
    }

    /**
     * Cancel one of the customer's own held redemptions and refund its points.
     */
    public function cancel(Request $request, Redemption $redemption): JsonResponse
    {
        return $this->attempt('Could not cancel the redemption.', function () use ($request, $redemption): JsonResponse {
            /** @var Customer $customer */
            $customer = $request->user();
            abort_unless($redemption->customer_id === $customer->id, 404);

            $redemption = $redemption->release(Redemption::STATUS_CANCELLED, 'Cancelled by customer');

            return response()->json([
                'success' => true,
                'message' => "Cancelled. {$redemption->points} points refunded.",
                'data' => $this->present($redemption, $customer->refresh()),
            ]);
        });
    }

    /**
     * Shape a redemption with the customer's balance after the change.
     *
     * @return array<string, mixed>
     */
    private function present(Redemption $redemption, Customer $customer): array
    {
        return [
            ...$redemption->load(self::RELATIONS)->toArray(),
            'customer' => [
                'points_balance' => $customer->points_balance,
                'qualifying_points' => $customer->qualifying_points,
                'tier' => Tier::find($customer->tier_id)?->name,
            ],
        ];
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Redemption;
use App\Models\Referral;
use App\Models\RestockRequest;
use App\Models\TierHistory;
use App\Services\RedemptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    /**
     * Everything about the signed-in customer, in one read: profile, orders, redemptions, restock
     * requests, the people they referred and their tier history.
     */
    public function show(Request $request, RedemptionService $redemptions): JsonResponse
    {
        return $this->attempt('Could not load your account.', function () use ($request, $redemptions): JsonResponse {
            $redemptions->expireOverdue();

            $customer = Customer::query()->withTotals()->findOrFail($request->user()->id);

            return response()->json([
                'success' => true,
                'message' => 'Account retrieved successfully',
                'data' => [
                    'profile' => $customer->toApiArray(),
                    'orders' => $customer->orders()->with(Order::RELATIONS)->latest('id')->get()
                        ->map(fn (Order $order): array => $order->toApiArray()),
                    'redemptions' => $customer->redemptions()->with(Redemption::RELATIONS)->latest('id')->get()
                        ->map(fn (Redemption $redemption): array => $redemption->toApiArray()),
                    'restock_requests' => $customer->restockRequests()->latest('id')->get()
                        ->map(fn (RestockRequest $restockRequest): array => $restockRequest->toApiArray()),
                    'referrals' => $customer->referrals()->with('referred:id,name,created_at')->latest('id')->get()
                        ->map(fn (Referral $referral): array => $referral->toApiArray()),
                    'tier_history' => $customer->tierHistories()->with(['fromTier:id,name', 'toTier:id,name'])->latest('id')->get()
                        ->map(fn (TierHistory $change): array => $change->toApiArray()),
                ],
            ]);
        });
    }

    /**
     * Update the signed-in customer's own details.
     */
    public function update(Request $request): JsonResponse
    {
        return $this->attempt('Could not update your profile.', function () use ($request): JsonResponse {
            $data = $request->validate([
                'name' => ['sometimes', 'required', 'string', 'max:255'],
                'name_kh' => ['sometimes', 'nullable', 'string', 'max:255'],
                'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
                'province' => ['sometimes', 'nullable', 'string', 'max:255'],
            ]);

            $request->user()->update($data);

            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully',
                'data' => Customer::query()->withTotals()->findOrFail($request->user()->id)->toApiArray(),
            ]);
        });
    }
}

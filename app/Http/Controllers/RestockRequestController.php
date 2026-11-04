<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Reward;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RestockRequestController extends Controller
{
    /**
     * Ask to be notified when a sold-out reward is back in stock.
     */
    public function store(Request $request): JsonResponse
    {
        return $this->attempt('Could not save your request.', function () use ($request): JsonResponse {
            $data = $request->validate([
                'reward_id' => ['required', 'integer'],
                'variant_id' => ['nullable', 'integer'],
            ]);
            $customer = $request->user();
            $reward = Reward::query()->with('variants')->findOrFail($data['reward_id']);
            $variantId = $data['variant_id'] ?? null;

            if (! in_array($variantId, $reward->variantIds(), true)) {
                throw ValidationException::withMessages(['variant_id' => 'Choose an option for this reward.']);
            }

            $waiting = $customer->restockRequests()
                ->where('reward_id', $reward->id)
                ->where('variant_id', $variantId)
                ->whereNull('notified_at')
                ->exists();

            if ($waiting) {
                throw ValidationException::withMessages(['reward_id' => 'You will already be notified when this is back in stock.']);
            }

            $restockRequest = $customer->restockRequests()->create(['reward_id' => $reward->id, 'variant_id' => $variantId]);
            ActivityLog::record('RESTOCK_REQUESTED', "Asked to be notified when {$reward->name} is back in stock", $customer);

            return response()->json([
                'success' => true,
                'message' => 'We will let you know when it is back in stock',
                'data' => $restockRequest->toApiArray(),
            ], 201);
        });
    }

    /**
     * Remove one of the signed-in customer's own requests.
     */
    public function destroy(Request $request, int $restockRequest): JsonResponse
    {
        return $this->attempt('Could not remove your request.', function () use ($request, $restockRequest): JsonResponse {
            $request->user()->restockRequests()->findOrFail($restockRequest)->delete();

            return response()->json(['success' => true, 'message' => 'Request removed']);
        });
    }
}

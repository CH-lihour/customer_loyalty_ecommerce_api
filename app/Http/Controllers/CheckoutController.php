<?php

namespace App\Http\Controllers;

use App\Http\Requests\CheckoutRequest;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;

class CheckoutController extends Controller
{
    /**
     * Place an order for the signed-in customer.
     */
    public function store(CheckoutRequest $request, OrderService $orders): JsonResponse
    {
        return $this->attempt('Could not place your order. Please try again.', function () use ($request, $orders): JsonResponse {
            $data = $request->validated();

            return response()->json([
                'success' => true,
                'message' => 'Order placed successfully',
                'data' => $orders->place($request->user(), $data['items'], $data['address'], $data['phone'])->toApiArray(),
            ], 201);
        });
    }
}

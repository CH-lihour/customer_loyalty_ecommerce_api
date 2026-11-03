<?php

namespace App\Http\Controllers;

use App\Http\Requests\SupplierRequest;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;

class SupplierController extends Controller
{
    /**
     * Add a supplier or partner.
     */
    public function store(SupplierRequest $request): JsonResponse
    {
        return $this->attempt('Could not add the supplier.', fn (): JsonResponse => response()->json([
            'success' => true,
            'message' => 'Supplier created successfully',
            'data' => Supplier::create($request->validated())->refresh(),
        ], 201));
    }

    /**
     * Update a supplier or partner; PATCH with only `is_active` toggles its status.
     */
    public function update(SupplierRequest $request, Supplier $supplier): JsonResponse
    {
        return $this->attempt('Could not update the supplier.', function () use ($request, $supplier): JsonResponse {
            $supplier->update($request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Supplier updated successfully',
                'data' => $supplier,
            ]);
        });
    }
}

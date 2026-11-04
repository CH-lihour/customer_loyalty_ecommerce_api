<?php

namespace App\Http\Controllers;

use App\Http\Requests\BadgeRequest;
use App\Models\Badge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BadgeController extends Controller
{
    /**
     * List a page of badges with how many customers have earned each.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->attempt('Could not load badges.', function () use ($request): JsonResponse {
            $badges = Badge::query()
                ->withCount('customers')
                ->orderBy('id')
                ->paginate($this->perPage($request));

            return $this->paginatedResponse($badges, 'Badges retrieved successfully');
        });
    }

    /**
     * Create a badge.
     */
    public function store(BadgeRequest $request): JsonResponse
    {
        return $this->attempt('Could not create the badge.', function () use ($request): JsonResponse {
            $badge = Badge::create($request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Badge created successfully',
                'data' => $badge->refresh()->loadCount('customers'),
            ], 201);
        });
    }

    /**
     * Show a badge.
     */
    public function show(Badge $badge): JsonResponse
    {
        return $this->attempt('Could not load the badge.', fn (): JsonResponse => response()->json([
            'success' => true,
            'message' => 'Badge retrieved successfully',
            'data' => $badge->loadCount('customers'),
        ]));
    }

    /**
     * Update a badge; PATCH changes only the fields sent.
     */
    public function update(BadgeRequest $request, Badge $badge): JsonResponse
    {
        return $this->attempt('Could not update the badge.', function () use ($request, $badge): JsonResponse {
            $badge->update($request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Badge updated successfully',
                'data' => $badge->loadCount('customers'),
            ]);
        });
    }

    /**
     * Delete a badge. Customers who earned it lose it; the admin confirms that first.
     */
    public function destroy(Badge $badge): JsonResponse
    {
        return $this->attempt('Could not delete the badge.', function () use ($badge): JsonResponse {
            $badge->delete();

            return response()->json([
                'success' => true,
                'message' => 'Badge deleted successfully',
            ]);
        });
    }
}

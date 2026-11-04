<?php

namespace App\Http\Controllers;

use App\Http\Requests\RewardRequest;
use App\Models\Reward;
use App\Models\Tier;
use App\Services\StockLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RewardController extends Controller
{
    /**
     * The minimum tier each reward is returned with.
     */
    private const RELATIONS = ['minTier:id,name'];

    public function __construct(private StockLedger $ledger) {}

    /**
     * List a page of rewards with their minimum tier, optionally filtered by status.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->attempt('Could not load rewards.', function () use ($request): JsonResponse {
            $filters = $request->validate(['is_active' => ['nullable', 'boolean']]);

            $rewards = Reward::query()
                ->with(self::RELATIONS)
                ->when(isset($filters['is_active']), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
                ->orderBy('id')
                ->paginate($this->perPage($request));

            $available = $this->ledger->availableByReward();

            return $this->paginatedResponse(
                $rewards,
                'Rewards retrieved successfully',
                fn (Reward $reward): array => $this->withStock($reward, $available),
            );
        });
    }

    /**
     * The tiers a reward can require, from the entry tier upwards, for staff who cannot open the tiers page.
     */
    public function tierOptions(): JsonResponse
    {
        return $this->attempt('Could not load tiers.', fn (): JsonResponse => response()->json([
            'success' => true,
            'message' => 'Tiers retrieved successfully',
            'data' => Tier::orderBy('min_points')->get(['id', 'name']),
        ]));
    }

    /**
     * Create a reward, storing an uploaded image when one is sent.
     */
    public function store(RewardRequest $request): JsonResponse
    {
        return $this->attempt('Could not create the reward.', function () use ($request): JsonResponse {
            $reward = DB::transaction(function () use ($request): Reward {
                $reward = Reward::create(Arr::except($request->validated(), 'image_file'));
                $this->storeImage($request, $reward);

                return $reward;
            });

            return response()->json([
                'success' => true,
                'message' => 'Reward created successfully',
                'data' => $this->withStock($reward->refresh()->load(self::RELATIONS)),
            ], 201);
        });
    }

    /**
     * Show a reward.
     */
    public function show(Reward $reward): JsonResponse
    {
        return $this->attempt('Could not load the reward.', fn (): JsonResponse => response()->json([
            'success' => true,
            'message' => 'Reward retrieved successfully',
            'data' => $this->withStock($reward->load(self::RELATIONS)),
        ]));
    }

    /**
     * Update a reward; PATCH changes only the fields sent. A new image replaces the stored one.
     */
    public function update(RewardRequest $request, Reward $reward): JsonResponse
    {
        return $this->attempt('Could not update the reward.', function () use ($request, $reward): JsonResponse {
            $previous = $reward->replicate();
            $reward->update(Arr::except($request->validated(), 'image_file'));
            $this->storeImage($request, $reward);

            // Drop the old uploaded file once the reward no longer uses it.
            if ($previous->hasUploadedImage() && $previous->image !== $reward->image) {
                $previous->deleteImageFile();
            }

            return response()->json([
                'success' => true,
                'message' => 'Reward updated successfully',
                'data' => $this->withStock($reward->load(self::RELATIONS)),
            ]);
        });
    }

    /**
     * Activate or deactivate a reward. Inactive rewards stay in the admin but customers cannot redeem them.
     */
    public function updateStatus(Request $request, Reward $reward): JsonResponse
    {
        return $this->attempt('Could not change the reward status.', function () use ($request, $reward): JsonResponse {
            $reward->update($request->validate(['is_active' => ['required', 'boolean']]));

            return response()->json([
                'success' => true,
                'message' => $reward->is_active ? 'Reward activated' : 'Reward deactivated',
                'data' => $this->withStock($reward->load(self::RELATIONS)),
            ]);
        });
    }

    /**
     * Delete a reward that has never been redeemed or stocked; one with history can only be deactivated.
     */
    public function destroy(Reward $reward): JsonResponse
    {
        return $this->attempt('Could not delete the reward.', function () use ($reward): JsonResponse {
            if ($reward->hasHistory()) {
                return response()->json([
                    'success' => false,
                    'message' => "{$reward->name} has redemptions or stock history, so it cannot be deleted. Deactivate it instead.",
                ], 422);
            }

            $reward->delete();
            $reward->deleteImageFile();

            return response()->json([
                'success' => true,
                'message' => 'Reward deleted successfully',
            ]);
        });
    }

    /**
     * Shape a reward for a response, with `stock` replayed from the ledger because levels are never stored.
     *
     * @param  Collection<int, int>|null  $available  Available units by reward id, when already replayed for a whole page.
     * @return array<string, mixed>
     */
    private function withStock(Reward $reward, ?Collection $available = null): array
    {
        $available ??= $this->ledger->availableByReward($reward->id);

        return [...$reward->toArray(), 'stock' => $available->get($reward->id, 0)];
    }

    /**
     * Store an uploaded image as the reward's image.
     */
    private function storeImage(RewardRequest $request, Reward $reward): void
    {
        if ($request->hasFile('image_file')) {
            $reward->update(['image' => $request->file('image_file')->store('rewards/'.$reward->id, Reward::DISK)]);
        }
    }
}

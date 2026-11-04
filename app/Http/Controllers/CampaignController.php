<?php

namespace App\Http\Controllers;

use App\Http\Requests\CampaignRequest;
use App\Models\Campaign;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class CampaignController extends Controller
{
    /**
     * List a page of campaigns, newest start first.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->attempt('Could not load campaigns.', function () use ($request): JsonResponse {
            $campaigns = Campaign::query()
                ->orderByDesc('start_date')
                ->orderBy('id')
                ->paginate($this->perPage($request));

            return $this->paginatedResponse($campaigns, 'Campaigns retrieved successfully');
        });
    }

    /**
     * The live campaigns shown as banners on the customer home page, in display order. Public, display fields only.
     */
    public function banners(): JsonResponse
    {
        return $this->attempt('Could not load banners.', fn (): JsonResponse => response()->json([
            'success' => true,
            'message' => 'Banners retrieved successfully',
            'data' => Campaign::query()
                ->live()
                ->where('show_on_home', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn (Campaign $campaign) => Arr::only($campaign->toArray(), Campaign::BANNER_FIELDS)),
        ]));
    }

    /**
     * Create a campaign, storing an uploaded banner image when one is sent.
     */
    public function store(CampaignRequest $request): JsonResponse
    {
        return $this->attempt('Could not create the campaign.', function () use ($request): JsonResponse {
            $campaign = DB::transaction(function () use ($request): Campaign {
                $campaign = Campaign::create(Arr::except($request->validated(), 'image_file'));
                $this->storeImage($request, $campaign);

                return $campaign;
            });

            return response()->json([
                'success' => true,
                'message' => 'Campaign created successfully',
                'data' => $campaign->refresh(),
            ], 201);
        });
    }

    /**
     * Show a campaign.
     */
    public function show(Campaign $campaign): JsonResponse
    {
        return $this->attempt('Could not load the campaign.', fn (): JsonResponse => response()->json([
            'success' => true,
            'message' => 'Campaign retrieved successfully',
            'data' => $campaign,
        ]));
    }

    /**
     * Update a campaign; PATCH changes only the fields sent. A new image replaces the stored one.
     */
    public function update(CampaignRequest $request, Campaign $campaign): JsonResponse
    {
        return $this->attempt('Could not update the campaign.', function () use ($request, $campaign): JsonResponse {
            $previous = $campaign->replicate();
            $campaign->update(Arr::except($request->validated(), 'image_file'));
            $this->storeImage($request, $campaign);

            // Drop the old uploaded file once the campaign no longer uses it.
            if ($previous->hasUploadedImage() && $previous->image !== $campaign->image) {
                $previous->deleteImageFile();
            }

            return response()->json([
                'success' => true,
                'message' => 'Campaign updated successfully',
                'data' => $campaign->refresh(),
            ]);
        });
    }

    /**
     * Activate or deactivate a campaign. An inactive campaign neither multiplies points nor shows a banner.
     */
    public function updateStatus(Request $request, Campaign $campaign): JsonResponse
    {
        return $this->attempt('Could not change the campaign status.', function () use ($request, $campaign): JsonResponse {
            $campaign->update($request->validate(['is_active' => ['required', 'boolean']]));

            return response()->json([
                'success' => true,
                'message' => $campaign->is_active ? 'Campaign activated' : 'Campaign deactivated',
                'data' => $campaign,
            ]);
        });
    }

    /**
     * Delete a campaign and its uploaded banner image.
     */
    public function destroy(Campaign $campaign): JsonResponse
    {
        return $this->attempt('Could not delete the campaign.', function () use ($campaign): JsonResponse {
            $campaign->delete();
            $campaign->deleteImageFile();

            return response()->json([
                'success' => true,
                'message' => 'Campaign deleted successfully',
            ]);
        });
    }

    /**
     * Store an uploaded image as the campaign's banner image.
     */
    private function storeImage(CampaignRequest $request, Campaign $campaign): void
    {
        if ($request->hasFile('image_file')) {
            $campaign->update(['image' => $request->file('image_file')->store('campaigns/'.$campaign->id, Campaign::DISK)]);
        }
    }
}

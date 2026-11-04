<?php

namespace App\Http\Controllers;

use App\Models\Referral;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReferralController extends Controller
{
    /**
     * List a page of referrals with both customers, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->attempt('Could not load referrals.', function () use ($request): JsonResponse {
            $referrals = Referral::query()
                ->with(['referrer:id,name', 'referred:id,name'])
                ->latest('id')
                ->paginate($this->perPage($request));

            return $this->paginatedResponse($referrals, 'Referrals retrieved successfully', fn (Referral $referral): array => $referral->toApiArray());
        });
    }
}

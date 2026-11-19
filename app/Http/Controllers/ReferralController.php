<?php

namespace App\Http\Controllers;

use App\Models\Referral;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReferralController extends Controller
{
    /**
     * List a page of referrals for staff, newest first, with totals for the whole program.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->attempt('Could not load the referrals.', function () use ($request): JsonResponse {
            $filters = $request->validate([
                'search' => ['nullable', 'string', 'max:255'],
                'status' => ['nullable', Rule::in(['pending', 'converted'])],
            ]);

            $referrals = Referral::query()
                ->with(['referrer:id,name,email,referral_code', 'referred:id,name,email', 'completer:id,name'])
                ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                    $term = '%'.mb_strtolower($search).'%';
                    $match = fn (Builder $query) => $query->whereRaw('lower(name) like ?', [$term])
                        ->orWhereRaw('lower(email) like ?', [$term])
                        ->orWhereRaw('lower(referral_code) like ?', [$term]);
                    $query->where(fn (Builder $query) => $query->whereHas('referrer', $match)->orWhereHas('referred', $match));
                })
                ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('is_completed', $status === 'converted'))
                ->latest()
                ->orderByDesc('id')
                ->paginate($this->perPage($request));

            $response = $this->paginatedResponse($referrals, 'Referrals retrieved successfully');
            $total = Referral::count();
            $converted = Referral::where('is_completed', true)->count();

            return $response->setData([
                ...$response->getData(true),
                'stats' => [
                    'total' => $total,
                    'converted' => $converted,
                    'pending' => $total - $converted,
                    'points_paid_out' => $converted * (Referral::REFERRER_POINTS + Referral::REFERRED_POINTS),
                ],
            ]);
        });
    }

    /**
     * Mark a pending referral converted and pay both customers their bonus.
     */
    public function complete(Request $request, Referral $referral): JsonResponse
    {
        return $this->attempt('Could not mark the referral converted.', function () use ($request, $referral): JsonResponse {
            $referral = $referral->complete($request->user()->id);

            return response()->json([
                'success' => true,
                'message' => 'Referral marked converted. Both customers got their points.',
                'data' => $referral->load(['referrer:id,name,email,referral_code', 'referred:id,name,email', 'completer:id,name']),
            ]);
        });
    }

    /**
     * Show the signed-in customer their code, the bonus amounts and the friends who joined with it.
     */
    public function mine(Request $request): JsonResponse
    {
        return $this->attempt('Could not load your referrals.', function () use ($request): JsonResponse {
            $customer = $request->user();
            $referrals = $customer->referralsMade()
                ->with('referred:id,name')
                ->latest()
                ->orderByDesc('id')
                ->get();
            $converted = $referrals->where('is_completed', true)->count();

            return response()->json([
                'success' => true,
                'message' => 'Referrals retrieved successfully',
                'data' => [
                    'referral_code' => $customer->referral_code,
                    'referrer_points' => Referral::REFERRER_POINTS,
                    'referred_points' => Referral::REFERRED_POINTS,
                    'stats' => [
                        'total' => $referrals->count(),
                        'converted' => $converted,
                        'points_earned' => $converted * Referral::REFERRER_POINTS,
                    ],
                    // Only the friend's name: their email and other details stay private.
                    'referrals' => $referrals->map(fn (Referral $referral) => [
                        'id' => $referral->id,
                        'name' => $referral->referred?->name,
                        'is_completed' => $referral->is_completed,
                        'completed_at' => $referral->completed_at,
                        'created_at' => $referral->created_at,
                    ])->values(),
                ],
            ]);
        });
    }
}

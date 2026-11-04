<?php

namespace App\Http\Controllers;

use App\Models\LoyaltyTransaction;
use App\Models\Redemption;
use Illuminate\Http\JsonResponse;

class ReportController extends Controller
{
    /**
     * The totals the dashboards show, so they need no access to the whole points ledger.
     */
    public function summary(): JsonResponse
    {
        return $this->attempt('Could not load the summary.', fn (): JsonResponse => response()->json([
            'success' => true,
            'message' => 'Summary retrieved successfully',
            'data' => [
                'points_issued' => (int) LoyaltyTransaction::query()->where('points', '>', 0)->sum('points'),
                'points_redeemed' => -(int) LoyaltyTransaction::query()->where('type', LoyaltyTransaction::TYPE_SPEND)->sum('points'),
                'rewards_redeemed' => Redemption::query()->count(),
            ],
        ]));
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Redemption;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Staff fulfilment of reward redemptions: ship or hand over a held reward, give it more time, or cancel it and
 * refund the customer.
 */
class AdminRedemptionController extends Controller
{
    /**
     * Relations every redemption response includes.
     *
     * @var list<string>
     */
    private const RELATIONS = ['customer:id,name,email', 'reward:id,name,name_kh,image,category,points_cost'];

    /**
     * List a page of redemptions, optionally filtered by status (or `closed` for every one no longer held) or a
     * search on the customer or reward, with a count in each status. Held ones are listed by the time their hold ends, others newest first.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->attempt('Could not load redemptions.', function () use ($request): JsonResponse {
            $filters = $request->validate([
                'search' => ['nullable', 'string', 'max:255'],
                'status' => ['nullable', Rule::in(Redemption::STATUSES)],
                'closed' => ['nullable', 'boolean'],
            ]);

            Redemption::expireOverdue();

            $redemptions = Redemption::query()
                ->with(self::RELATIONS)
                ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                    $term = '%'.mb_strtolower($search).'%';
                    $query->where(fn (Builder $query) => $query
                        ->whereHas('customer', fn (Builder $query) => $query
                            ->whereRaw('lower(name) like ?', [$term])
                            ->orWhereRaw('lower(email) like ?', [$term]))
                        ->orWhereHas('reward', fn (Builder $query) => $query->whereRaw('lower(name) like ?', [$term])));
                })
                ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
                ->when($request->boolean('closed'), fn (Builder $query) => $query->where('status', '!=', Redemption::STATUS_RESERVED))
                ->when(($filters['status'] ?? null) === Redemption::STATUS_RESERVED,
                    fn (Builder $query) => $query->orderBy('reserved_until')->orderBy('id'),
                    fn (Builder $query) => $query->latest('updated_at')->orderByDesc('id'))
                ->paginate($this->perPage($request));

            $response = $this->paginatedResponse($redemptions, 'Redemptions retrieved successfully');
            $counts = Redemption::query()->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status');

            return $response->setData([
                ...$response->getData(true),
                'stats' => collect(Redemption::STATUSES)->mapWithKeys(fn (string $status) => [$status => (int) ($counts[$status] ?? 0)]),
            ]);
        });
    }

    /**
     * Mark a held redemption as shipped or picked up.
     */
    public function fulfill(Request $request, Redemption $redemption): JsonResponse
    {
        return $this->attempt('Could not fulfil the redemption.', function () use ($request, $redemption): JsonResponse {
            $data = $request->validate(['method' => ['required', Rule::in(Redemption::FULFILLMENT_METHODS)]]);
            $redemption = $redemption->fulfill($data['method'], $request->user());

            return $this->respond($redemption, $data['method'] === Redemption::FULFILLMENT_SHIPPED ? 'Marked shipped' : 'Marked picked up');
        });
    }

    /**
     * Cancel a held redemption, returning its stock and refunding the customer's points.
     */
    public function cancel(Request $request, Redemption $redemption): JsonResponse
    {
        return $this->attempt('Could not cancel the redemption.', function () use ($request, $redemption): JsonResponse {
            $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
            $redemption = $redemption->release(Redemption::STATUS_CANCELLED, trim($data['reason']), $request->user());

            return $this->respond($redemption, "Cancelled and {$redemption->points} points refunded");
        });
    }

    /**
     * Give a held redemption more time before it expires.
     */
    public function extend(Request $request, Redemption $redemption): JsonResponse
    {
        return $this->attempt('Could not extend the hold.', function () use ($request, $redemption): JsonResponse {
            $data = $request->validate(['hours' => ['required', 'integer', 'min:1', 'max:720']]);
            $redemption = $redemption->extend($data['hours'], $request->user());

            return $this->respond($redemption, "Hold extended by {$data['hours']} hours");
        });
    }

    private function respond(Redemption $redemption, string $message): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $redemption->refresh()->load(self::RELATIONS),
        ]);
    }
}

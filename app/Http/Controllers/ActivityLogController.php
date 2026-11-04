<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    /**
     * List a page of the activity log, newest first, optionally filtered by event or search term.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->attempt('Could not load the activity log.', function () use ($request): JsonResponse {
            $filters = $request->validate([
                'event' => ['nullable', 'string', 'max:255'],
                'search' => ['nullable', 'string', 'max:255'],
            ]);

            $logs = ActivityLog::query()
                ->with(['customer:id,name', 'user:id,name'])
                ->when($filters['event'] ?? null, fn (Builder $query, string $event) => $query->where('event', $event))
                ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                    $query->whereRaw('lower(description) like ?', ['%'.mb_strtolower($search).'%']);
                })
                ->latest('id')
                ->paginate($this->perPage($request));

            return $this->paginatedResponse($logs, 'Activity log retrieved successfully', fn (ActivityLog $log): array => $log->toApiArray());
        });
    }
}

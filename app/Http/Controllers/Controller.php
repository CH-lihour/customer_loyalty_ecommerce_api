<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Rows per page when the request does not ask for a size.
     */
    protected const DEFAULT_PER_PAGE = 30;

    /**
     * The largest page a request may ask for.
     */
    protected const MAX_PER_PAGE = 100;

    /**
     * Validate and return the requested page size.
     */
    protected function perPage(Request $request): int
    {
        $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ]);

        return $request->integer('per_page', self::DEFAULT_PER_PAGE);
    }

    /**
     * Respond with one page of rows plus the paging details the admin tables need.
     *
     * @param  callable(mixed): mixed|null  $map  Shapes each row; rows are returned as-is when omitted.
     */
    protected function paginatedResponse(LengthAwarePaginator $page, string $message, ?callable $map = null): JsonResponse
    {
        $rows = collect($page->items());

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $map ? $rows->map($map)->values() : $rows,
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }
}

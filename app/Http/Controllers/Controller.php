<?php

namespace App\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

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
     * Run a controller action, turning an unexpected failure into a JSON error the frontend can show.
     *
     * Validation, authentication, authorization and HTTP errors (422, 401, 403, 404, 503...) are rethrown so
     * Laravel renders them as usual. Anything else is reported to the log and answered with a 500 that names
     * what failed; the exception message is only included while `app.debug` is on.
     *
     * @param  callable(): JsonResponse  $action
     */
    protected function attempt(string $failureMessage, callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (ValidationException|AuthenticationException|AuthorizationException|ModelNotFoundException|HttpExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => $failureMessage,
                ...(config('app.debug') ? ['error' => $exception->getMessage()] : []),
            ], 500);
        }
    }

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

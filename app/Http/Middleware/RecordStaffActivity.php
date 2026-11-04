<?php

namespace App\Http\Middleware;

use App\Models\ActivityLog;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds a successful staff change to the activity log, named after its controller and action:
 * "PRODUCT_CREATED" for `ProductController@store`.
 */
class RecordStaffActivity
{
    /**
     * Words for the standard resource actions; any other action uses its own name.
     *
     * @var array<string, string>
     */
    private const ACTIONS = ['store' => 'CREATED', 'update' => 'UPDATED', 'destroy' => 'DELETED'];

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->isMethodSafe() && $response->isSuccessful() && $request->user()) {
            $this->record($request, $response);
        }

        return $response;
    }

    /**
     * Log the change, naming what it changed.
     */
    private function record(Request $request, Response $response): void
    {
        $route = $request->route();
        $subject = Str::of(class_basename($route->getController()))->before('Controller')->snake()->upper()->toString();
        $method = $route->getActionMethod();
        $action = self::ACTIONS[$method] ?? Str::of($method)->snake()->upper()->toString();
        $event = "{$subject}_{$action}";

        $target = collect($route->parameters())->first();
        $name = is_object($target)
            ? ($target->name ?? $target->id ?? null)
            : ($response instanceof JsonResponse ? data_get($response->getData(true), 'data.name') : null);

        $label = Str::ucfirst(Str::lower(str_replace('_', ' ', $event)));

        ActivityLog::record($event, $name ? "{$label}: {$name}" : $label, staff: $request->user());
    }
}

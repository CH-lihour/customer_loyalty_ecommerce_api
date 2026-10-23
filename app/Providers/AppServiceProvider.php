<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Sliding expiration: reject tokens idle for longer than the timeout.
        // Sanctum updates "last_used_at" after a token passes this check.
        Sanctum::authenticateAccessTokensUsing(function (PersonalAccessToken $token, bool $isValid) {
            $idleTimeout = config('sanctum.idle_timeout');

            if (! $isValid || ! $idleTimeout) {
                return $isValid;
            }

            $lastActivity = $token->last_used_at ?? $token->created_at;

            return $lastActivity->gt(now()->subMinutes((int) $idleTimeout));
        });

        Gate::define('manage-staff', fn (User $user): bool => $user->isSuperAdmin());
        Gate::define('access-page', fn (User $user, string $page): bool => $user->canAccessPage($page));
    }
}

<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BadgeController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CustomerAuthController;
use App\Http\Controllers\PointController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\RewardController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\TierController;
use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Staff Side
Route::prefix('backend')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        // Logout
        Route::post('/logout', [AuthController::class, 'logout']);
        // Profile, with the role and pages the frontend uses for menu access
        Route::get('/profile', function (Request $request) {
            return response()->json([
                'success' => true,
                'message' => 'Profile retrieved successfully',
                'data' => $request->user()->load('role.permissions')->toApiArray(),
            ]);
        });
        // Catalog setup
        Route::apiResource('categories', CategoryController::class)->middleware("can:access-page,'categories'");
        Route::apiResource('products', ProductController::class)->middleware("can:access-page,'products'");
        // Loyalty setup
        Route::apiResource('tiers', TierController::class)->middleware("can:access-page,'tiers'");
        Route::apiResource('badges', BadgeController::class)->middleware("can:access-page,'badges'");
        // Points ledger: rows are only added, so it has no update or delete routes
        Route::middleware("can:access-page,'points'")->prefix('points')->group(function () {
            Route::get('transactions', [PointController::class, 'transactions']);
            Route::get('customers', [PointController::class, 'customers']);
            Route::post('adjustments', [PointController::class, 'adjust']);
        });
        Route::middleware("can:access-page,'campaigns'")->group(function () {
            Route::patch('campaigns/{campaign}/status', [CampaignController::class, 'updateStatus']);
            Route::apiResource('campaigns', CampaignController::class);
        });
        Route::middleware("can:access-page,'rewards'")->group(function () {
            Route::get('rewards/tier-options', [RewardController::class, 'tierOptions']);
            Route::patch('rewards/{reward}/status', [RewardController::class, 'updateStatus']);
            Route::apiResource('rewards', RewardController::class);
        });
        // Staff accounts and role permissions (Super Admin only)
        Route::middleware('can:manage-staff')->group(function () {
            Route::apiResource('roles', RoleController::class);
            Route::apiResource('users', UserController::class)->except('destroy');
        });
    });
});

// Customer Side
// Home-page banners, public so guests see them too
Route::get('/campaigns/banners', [CampaignController::class, 'banners']);

Route::post('/register', [CustomerAuthController::class, 'register']);
Route::post('/login', [CustomerAuthController::class, 'login']);

Route::middleware('auth:customer')->group(function () {
    // Logout
    Route::post('/logout', [CustomerAuthController::class, 'logout']);
    // Profile
    Route::get('/profile', function (Request $request) {
        return response()->json([
            'success' => true,
            'message' => 'Profile retrieved successfully',
            'data' => $request->user()->only(['id', 'name', 'email', 'phone', 'points_balance']),
        ]);
    });
    // Own points history
    Route::get('/points', [PointController::class, 'history']);
});

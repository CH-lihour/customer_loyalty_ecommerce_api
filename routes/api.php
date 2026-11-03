<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CustomerAuthController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\StockItemController;
use App\Http\Controllers\StockLocationController;
use App\Http\Controllers\StockMovementController;
use App\Http\Controllers\StocktakeController;
use App\Http\Controllers\SupplierController;
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
        // Reward stock: the ledger, stock rules, locations, suppliers and stocktakes (any inventory page)
        Route::middleware('can:access-stock')->group(function () {
            Route::get('/stock', [StockController::class, 'index']);
            Route::get('/stock-movements', [StockMovementController::class, 'index']);
            Route::post('/stock-movements', [StockMovementController::class, 'store']);
            Route::post('/stocktakes', [StocktakeController::class, 'store']);
            Route::patch('/stocktakes/{stocktake}/lines/{line}', [StocktakeController::class, 'count'])->scopeBindings();

            Route::middleware("can:manage-stock,'manager'")->group(function () {
                Route::put('/stock-items/{reward}', [StockItemController::class, 'update']);
                Route::apiResource('stock-locations', StockLocationController::class)->only(['store', 'update']);
                Route::apiResource('suppliers', SupplierController::class)->only(['store', 'update']);
                Route::post('/stocktakes/{stocktake}/post', [StocktakeController::class, 'post']);
                Route::post('/stocktakes/{stocktake}/cancel', [StocktakeController::class, 'cancel']);
            });
        });
        // Staff accounts and role permissions (Super Admin only)
        Route::middleware('can:manage-staff')->group(function () {
            Route::apiResource('roles', RoleController::class);
            Route::apiResource('users', UserController::class)->except('destroy');
        });
    });
});

// Customer Side
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
});

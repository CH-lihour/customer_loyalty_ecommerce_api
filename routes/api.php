<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CustomerAuthController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\RoleController;
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

<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CustomerAuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Admin Side
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    // Logout
    Route::post('/logout', [AuthController::class, 'logout']);
    // Profile
    Route::get('/profile', function (Request $request) {
        return response()->json([
            'success' => true,
            'message' => 'Profile retrieved successfully',
            'data' => $request->user()->only(['id', 'name', 'email']),
        ]);
    });
});

// Customer Side
Route::prefix('customer')->group(function () {
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
});

<?php

use App\Http\Controllers\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    // Logout
    Route::post('/logout', [AuthController::class, 'logout']);
    // Profile
    Route::get('/profile', function(Request $request){
        return response()->json([
            'success' => true,
            'message' => 'Profile retrieved successfully',
            'data'    => $request->user()->only(['id', 'name', 'email'])
        ]);
    });
});

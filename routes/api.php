<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Public authentication routes
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

// Protected authentication routes
Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/profile', [AuthController::class, 'profile']);
        Route::post('/refresh-token', [AuthController::class, 'refreshToken']);
        Route::post('/revoke-all-tokens', [AuthController::class, 'revokeAllTokens']);
    });

    // Legacy user endpoint for compatibility
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // Health check for authenticated users
    Route::get('/health', function () {
        return response()->json([
            'status' => 'success',
            'message' => 'API is running and user is authenticated.',
            'timestamp' => now()->toISOString(),
        ]);
    });
});

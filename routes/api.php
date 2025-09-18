<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\MotorcycleController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\RentalController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

// Public authentication routes
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

// Public motorcycle routes (no authentication required for browsing)
Route::prefix('motorcycles')->group(function () {
    Route::get('/', [MotorcycleController::class, 'index']);
    Route::get('/{motorcycle}', [MotorcycleController::class, 'show']);
    Route::get('/{motorcycle}/availability', [MotorcycleController::class, 'checkAvailability']);
});

// Public rental price calculation
Route::post('/rentals/calculate-price', [RentalController::class, 'calculatePrice']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // Authentication routes
    Route::prefix('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/profile', [AuthController::class, 'profile']);
        Route::post('/refresh-token', [AuthController::class, 'refreshToken']);
        Route::post('/revoke-all-tokens', [AuthController::class, 'revokeAllTokens']);
    });

    // Motorcycle management routes
    Route::prefix('motorcycles')->group(function () {
        // Admin only routes
        Route::middleware('role:admin')->group(function () {
            Route::post('/', [MotorcycleController::class, 'store']);
            Route::put('/{motorcycle}', [MotorcycleController::class, 'update']);
            Route::delete('/{motorcycle}', [MotorcycleController::class, 'destroy']);
            Route::post('/{motorcycle}/images', [MotorcycleController::class, 'uploadImages']);
        });
    });

    // Rental management routes
    Route::prefix('rentals')->group(function () {
        Route::get('/', [RentalController::class, 'index']);
        Route::post('/', [RentalController::class, 'store']);
        Route::get('/{rental}', [RentalController::class, 'show']);

        // Rental actions
        Route::patch('/{rental}/complete', [RentalController::class, 'complete']);
        Route::patch('/{rental}/cancel', [RentalController::class, 'cancel']);
    });

    // Payment processing routes
    Route::prefix('payments')->group(function () {
        Route::post('/process', [PaymentController::class, 'processPayment']);
        Route::post('/{payment}/verify', [PaymentController::class, 'verifyPayment']);
        Route::get('/{payment}/details', [PaymentController::class, 'getPaymentDetails']);

        // Admin only refund route
        Route::middleware('role:admin')->group(function () {
            Route::post('/{payment}/refund', [PaymentController::class, 'refund']);
        });
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

// Webhook routes (no authentication, but should have signature verification in production)
Route::prefix('webhooks')->group(function () {
    Route::post('/payment-notification', function (Request $request) {
        // This would handle payment gateway webhooks
        // In production, verify webhook signatures here
        Log::info('Payment webhook received', $request->all());

        return response()->json(['status' => 'received']);
    });
});

// Health check for API status
Route::get('/health', function () {
    return response()->json([
        'status' => 'success',
        'message' => 'Motorcycle Rental API is running.',
        'version' => '1.0.0',
        'timestamp' => now()->toISOString(),
        'environment' => app()->environment(),
    ]);
});

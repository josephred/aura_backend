<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\DependentController;
use App\Http\Controllers\AddressController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\ChatController;

// 0. Authentication (public)
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

// 1. Clinical Services Catalog (public)
Route::get('/services', [ServiceController::class, 'index']);

Route::middleware('auth:sanctum')->group(function () {
    // 0b. Authenticated session
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    // 2. Family Dependents ABM
    Route::get('/dependents', [DependentController::class, 'index']);
    Route::post('/dependents', [DependentController::class, 'store']);
    Route::delete('/dependents/{id}', [DependentController::class, 'destroy']);

    // 3. User Addresses Frequent list
    Route::get('/addresses', [AddressController::class, 'index']);
    Route::post('/addresses', [AddressController::class, 'store']);

    // 4. Booking Management
    Route::get('/bookings/active', [BookingController::class, 'active']);
    Route::post('/bookings', [BookingController::class, 'store']);
    Route::post('/bookings/{id}/cancel', [BookingController::class, 'cancel']);
    Route::post('/bookings/{id}/simulate-step', [BookingController::class, 'simulateStep']);

    // 5. Chat tele-assistance & Simulated Responses
    Route::get('/bookings/{requestId}/chat', [ChatController::class, 'index']);
    Route::post('/bookings/{requestId}/chat', [ChatController::class, 'store']);

    // 6. Clinical History Digital Log
    Route::get('/history', [BookingController::class, 'history']);
});

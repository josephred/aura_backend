<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DeviceTokenController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\DependentController;
use App\Http\Controllers\AddressController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\PaymentMethodController;
use App\Http\Controllers\SocialAuthController;

// 0. Authentication (public)
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/social', [SocialAuthController::class, 'loginOrRegister']);

// 1. Clinical Services Catalog (public)
Route::get('/services', [ServiceController::class, 'index']);

// 1b. Professionals catalog and availability (public)
Route::get('/professionals', [AppointmentController::class, 'professionals']);
Route::get('/professionals/{id}/slots', [AppointmentController::class, 'slots']);

// Payment notifications from Mercado Pago (public; payment data is
// re-fetched server-side so the body cannot be forged)
Route::post('/webhooks/mercadopago', [PaymentWebhookController::class, 'mercadoPago']);

Route::middleware('auth:sanctum')->group(function () {
    // 0b. Authenticated session
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    // 2. Family Dependents ABM
    Route::get('/dependents', [DependentController::class, 'index']);
    Route::post('/dependents', [DependentController::class, 'store']);
    Route::put('/dependents/{id}', [DependentController::class, 'update']);
    Route::delete('/dependents/{id}', [DependentController::class, 'destroy']);

    // 3. User Addresses Frequent list
    Route::get('/addresses', [AddressController::class, 'index']);
    Route::post('/addresses', [AddressController::class, 'store']);
    Route::put('/addresses/{id}', [AddressController::class, 'update']);
    Route::delete('/addresses/{id}', [AddressController::class, 'destroy']);

    // 3b. User Payment Methods
    Route::get('/payment-methods', [PaymentMethodController::class, 'index']);
    Route::post('/payment-methods', [PaymentMethodController::class, 'store']);
    Route::delete('/payment-methods/{id}', [PaymentMethodController::class, 'destroy']);

    // 3b. Push notification device tokens
    Route::post('/device-tokens', [DeviceTokenController::class, 'store']);
    Route::delete('/device-tokens', [DeviceTokenController::class, 'destroy']);

    // 4. Booking Management
    Route::get('/bookings/active', [BookingController::class, 'active']);
    Route::post('/bookings', [BookingController::class, 'store']);
    Route::post('/bookings/{id}/cancel', [BookingController::class, 'cancel']);
    Route::post('/bookings/{id}/simulate-step', [BookingController::class, 'simulateStep']);
    Route::get('/bookings/{id}/sse', [BookingController::class, 'streamStatus']);
    Route::get('/bookings/{id}/payment-status', [BookingController::class, 'paymentStatus']);

    // 4b. Scheduled Appointments
    Route::get('/appointments', [AppointmentController::class, 'index']);
    Route::post('/appointments', [AppointmentController::class, 'store']);
    Route::post('/appointments/{id}/cancel', [AppointmentController::class, 'cancel']);
    Route::get('/appointments/{id}/payment-status', [AppointmentController::class, 'paymentStatus']);

    // 5. Chat tele-assistance & Simulated Responses
    Route::get('/bookings/{requestId}/chat', [ChatController::class, 'index']);
    Route::post('/bookings/{requestId}/chat', [ChatController::class, 'store']);

    // 6. Clinical History Digital Log
    Route::get('/history', [BookingController::class, 'history']);
});

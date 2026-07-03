<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DoctorDashboardController;
use App\Http\Controllers\StaffAuthController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/politica-privacidad', function () {
    return view('privacy');
});

// Staff login for the doctor portal
Route::get('/doctor/login', [StaffAuthController::class, 'showLogin']);
Route::post('/doctor/login', [StaffAuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/doctor/logout', [StaffAuthController::class, 'logout']);

// Doctor Dashboard Portal (staff session required)
Route::middleware('staff.auth')->group(function () {
    Route::get('/doctor', [DoctorDashboardController::class, 'index']);
    Route::get('/doctor/api/bookings', [DoctorDashboardController::class, 'bookings']);
    Route::post('/doctor/api/bookings/{id}/status', [DoctorDashboardController::class, 'updateStatus']);
    Route::get('/doctor/api/bookings/{id}/messages', [DoctorDashboardController::class, 'getMessages']);
    Route::post('/doctor/api/bookings/{id}/messages', [DoctorDashboardController::class, 'sendMessage']);
});

<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DoctorDashboardController;

Route::get('/', function () {
    return view('welcome');
});

// Doctor Dashboard Portal
Route::get('/doctor', [DoctorDashboardController::class, 'index']);
Route::get('/doctor/api/bookings', [DoctorDashboardController::class, 'bookings']);
Route::post('/doctor/api/bookings/{id}/status', [DoctorDashboardController::class, 'updateStatus']);
Route::get('/doctor/api/bookings/{id}/messages', [DoctorDashboardController::class, 'getMessages']);
Route::post('/doctor/api/bookings/{id}/messages', [DoctorDashboardController::class, 'sendMessage']);

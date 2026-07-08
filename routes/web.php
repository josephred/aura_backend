<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DoctorAgendaController;
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

    // Scheduled appointments agenda
    Route::get('/doctor/agenda', [DoctorAgendaController::class, 'index']);
    Route::get('/doctor/api/appointments', [DoctorAgendaController::class, 'appointments']);
    Route::post('/doctor/api/appointments/{id}/status', [DoctorAgendaController::class, 'updateStatus']);
    Route::get('/doctor/agenda/call/{id}', [DoctorAgendaController::class, 'callPage']);
    Route::get('/doctor/api/appointments/{id}/webrtc-config', [DoctorAgendaController::class, 'webrtcConfig']);
    Route::post('/doctor/api/appointments/{id}/video-signals', [DoctorAgendaController::class, 'postVideoSignal']);
    Route::get('/doctor/api/appointments/{id}/video-signals', [DoctorAgendaController::class, 'videoSignals']);
    Route::get('/doctor/api/professionals/{id}/schedules', [DoctorAgendaController::class, 'schedules']);
    Route::post('/doctor/api/professionals/{id}/schedules', [DoctorAgendaController::class, 'storeSchedule']);
    Route::delete('/doctor/api/professionals/{id}/schedules/{blockId}', [DoctorAgendaController::class, 'destroySchedule']);

    // Portal account management (admin only, enforced in controller)
    Route::get('/doctor/api/accounts', [DoctorAgendaController::class, 'accounts']);
    Route::post('/doctor/api/professionals/{id}/account', [DoctorAgendaController::class, 'saveAccount']);
});

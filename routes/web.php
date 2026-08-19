<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\ClinicalMediaController;
use App\Http\Controllers\DoctorAgendaController;
use App\Http\Controllers\DoctorDashboardController;
use App\Http\Controllers\LabPortalController;
use App\Http\Controllers\StaffAuthController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/politica-privacidad', function () {
    return view('privacy');
});

// Clinical attachments. Reachable from the app (Sanctum token) and from the
// portal (staff session), never anonymously — these are health data.
// Authorization is done inside the controller because the two callers use
// different mechanisms: a Sanctum bearer token (app) and the staff session
// (portal), which is not a Laravel auth guard.
Route::get('/media/bookings/{bookingId}/{kind}', [ClinicalMediaController::class, 'show'])
    ->where('kind', 'prescription|symptom-audio')
    ->name('media.booking.show');

// Lab reports (Módulo E). Same authorization rule as the attachments above,
// plus one extra door: a short-lived signed link. The app has to hand the PDF
// to the system viewer, which does not carry the patient's bearer token, so
// /api/lab/results/{id}/link mints a signature that expires in minutes instead
// of leaving the report readable by anyone with the URL.
Route::get('/media/lab-results/{resultId}', [ClinicalMediaController::class, 'labResult'])
    ->name('lab-results.show');

// Staff login for the doctor portal
Route::get('/doctor/login', [StaffAuthController::class, 'showLogin']);
Route::post('/doctor/login', [StaffAuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/doctor/logout', [StaffAuthController::class, 'logout']);

// Doctor Dashboard Portal (staff session required)
Route::middleware('staff.auth')->group(function () {
    Route::get('/doctor', [DoctorDashboardController::class, 'index']);
    Route::get('/doctor/api/bookings', [DoctorDashboardController::class, 'bookings']);
    Route::post('/doctor/api/bookings/{id}/status', [DoctorDashboardController::class, 'updateStatus']);
    Route::post('/doctor/api/bookings/{id}/location', [DoctorDashboardController::class, 'updateLocation']);
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

    // Lab area (Módulo E): publish collection blocks, see the day's route and
    // upload results.
    Route::get('/doctor/laboratorio', [LabPortalController::class, 'index']);
    Route::get('/doctor/api/lab/schedules', [LabPortalController::class, 'schedules']);
    Route::post('/doctor/api/lab/schedules', [LabPortalController::class, 'storeSchedule']);
    Route::delete('/doctor/api/lab/schedules/{blockId}', [LabPortalController::class, 'destroySchedule']);
    Route::get('/doctor/api/lab/collections', [LabPortalController::class, 'collections']);
    Route::post('/doctor/api/lab/collections/{id}/results', [LabPortalController::class, 'uploadResult']);
    Route::get('/doctor/api/lab/earnings', [LabPortalController::class, 'earnings']);

    // Professional profile and curriculum
    Route::get('/doctor/api/profile', [StaffProfileController::class, 'show']);
    Route::post('/doctor/api/profile', [StaffProfileController::class, 'update']);
});

// Operations panel — administration only, fully separate from the clinical
// portal above. Admins land here after login; professionals never see it.
Route::middleware('admin.auth')->group(function () {
    Route::get('/admin', [AdminDashboardController::class, 'index']);
    Route::get('/admin/api/metrics', [AdminDashboardController::class, 'metrics']);
    Route::get('/admin/api/zones', [AdminDashboardController::class, 'zones']);
    Route::get('/admin/api/professionals', [AdminDashboardController::class, 'professionals']);
    Route::post('/admin/api/professionals/{id}', [AdminDashboardController::class, 'updateProfessional']);
    Route::post('/admin/api/professionals/{id}/account', [AdminDashboardController::class, 'saveAccount']);
});

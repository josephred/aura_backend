<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DoctorDashboardController;
use App\Http\Controllers\StaffProfileController;
use App\Http\Controllers\DeviceTokenController;
use App\Http\Controllers\DispatchController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\QueueController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\DependentController;
use App\Http\Controllers\AddressController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\LabController;
use App\Http\Controllers\LabPortalController;
use App\Http\Controllers\ClinicalMediaController;
use App\Http\Controllers\PaymentMethodController;
use App\Http\Controllers\RatingController;
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

// 1c. Zone-based wait estimate (public: shown before the user commits)
Route::get('/dispatch/eta', [DispatchController::class, 'eta']);

// 1d. Lab collection availability (public: the patient must be able to see
// which days have slots before deciding to request anything)
Route::get('/lab/slots', [LabController::class, 'slots'])->middleware('throttle:30,1');
Route::get('/lab/availability', [LabController::class, 'availability'])->middleware('throttle:30,1');

// 1e. Subscription plans (public catalogue)
Route::get('/subscriptions/plans', [\App\Http\Controllers\SubscriptionController::class, 'plans']);

// Payment notifications from Mercado Pago (public; payment data is
// re-fetched server-side so the body cannot be forged)
Route::post('/webhooks/mercadopago', [PaymentWebhookController::class, 'mercadoPago']);

Route::middleware('auth:sanctum')->group(function () {
    // 0b. Authenticated session
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    // 0c. Subscriptions management
    Route::get('/subscriptions/current', [\App\Http\Controllers\SubscriptionController::class, 'current']);
    Route::post('/subscriptions/subscribe', [\App\Http\Controllers\SubscriptionController::class, 'subscribe']);
    Route::post('/subscriptions/cancel', [\App\Http\Controllers\SubscriptionController::class, 'cancel']);

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
    Route::get('/bookings/{id}/sse', [BookingController::class, 'streamStatus']);
    Route::get('/bookings/{id}/payment-status', [BookingController::class, 'paymentStatus']);
    Route::post('/bookings/{id}/rating', [RatingController::class, 'store']);
    Route::get('/transport/quote', [BookingController::class, 'quoteTransport']);

    // 4b. Scheduled Appointments
    Route::get('/appointments', [AppointmentController::class, 'index']);
    Route::post('/appointments', [AppointmentController::class, 'store']);
    Route::post('/appointments/{id}/cancel', [AppointmentController::class, 'cancel']);
    Route::get('/appointments/{id}/payment-status', [AppointmentController::class, 'paymentStatus']);
    Route::get('/appointments/{id}/video-join', [AppointmentController::class, 'videoJoin']);
    Route::post('/appointments/{id}/video-signals', [AppointmentController::class, 'postVideoSignal']);
    Route::get('/appointments/{id}/video-signals', [AppointmentController::class, 'videoSignals']);

    // 5. Chat tele-assistance (replies come from the professional's portal)
    Route::get('/bookings/{requestId}/chat', [ChatController::class, 'index']);
    Route::post('/bookings/{requestId}/chat', [ChatController::class, 'store']);

    // 4c. Lab collections (Módulo E). Scheduled into a published slot instead
    // of dispatched immediately, so they get their own endpoints rather than
    // riding on /bookings.
    Route::get('/lab/requests', [LabController::class, 'index']);
    Route::post('/lab/requests', [LabController::class, 'store']);
    Route::patch('/lab/requests/{id}/notes', [LabController::class, 'updateNotes']);
    Route::post('/lab/requests/{id}/cancel', [LabController::class, 'cancel']);
    Route::get('/lab/requests/{id}/payment-status', [LabController::class, 'paymentStatus']);
    // "Mis Exámenes": historical, downloadable reports.
    Route::get('/lab/results', [LabController::class, 'results']);
    Route::get('/lab/results/{id}/link', [LabController::class, 'resultLink']);

    // 5b. Clinical attachments signed link
    Route::get('/media/bookings/{bookingId}/{kind}/link', [ClinicalMediaController::class, 'signedLink'])
        ->where('kind', 'prescription|symptom-audio');

    // 6. Clinical History Digital Log
    Route::get('/history', [BookingController::class, 'history']);

    // 7. Staff area for the mobile app.
    //
    // Deliberately points at the very same controllers the web portal uses:
    // the clinical rules (zone scoping, claiming a request, duty status,
    // history records) have one implementation, and the transport — session
    // or bearer token — is resolved by ResolvesStaffScope.
    Route::middleware('staff.api')->prefix('staff')->group(function () {
        Route::get('/bookings', [DoctorDashboardController::class, 'bookings']);

        // Cola de pacientes por servicio, y toma explicita.
        Route::get('/queue', [QueueController::class, 'index']);
        Route::post('/bookings/{id}/claim', [QueueController::class, 'claim']);
        Route::post('/bookings/{id}/release', [QueueController::class, 'release']);
        Route::post('/bookings/{id}/status', [DoctorDashboardController::class, 'updateStatus']);
        Route::post('/bookings/{id}/location', [DoctorDashboardController::class, 'updateLocation']);
        Route::get('/bookings/{id}/messages', [DoctorDashboardController::class, 'getMessages']);
        Route::post('/bookings/{id}/messages', [DoctorDashboardController::class, 'sendMessage']);
        Route::get('/duty', [StaffProfileController::class, 'show']);
        Route::post('/duty', [StaffProfileController::class, 'updateDuty']);
        Route::get('/profile', [StaffProfileController::class, 'show']);
        Route::post('/profile', [StaffProfileController::class, 'update']);

        // Lab area for the laboratorista working from the phone. Same
        // controller as the web portal, so the scheduling rules exist once.
        Route::get('/lab/schedules', [LabPortalController::class, 'schedules']);
        Route::post('/lab/schedules', [LabPortalController::class, 'storeSchedule']);
        Route::delete('/lab/schedules/{blockId}', [LabPortalController::class, 'destroySchedule']);
        Route::get('/lab/collections', [LabPortalController::class, 'collections']);
        Route::post('/lab/collections/{id}/results', [LabPortalController::class, 'uploadResult']);
        Route::get('/lab/earnings', [LabPortalController::class, 'earnings']);
    });

    // 7b. Operations panel for the mobile app (operator/admin role only).
    Route::middleware('staff.api:operator')->prefix('staff/admin')->group(function () {
        Route::get('/metrics', [AdminDashboardController::class, 'metrics']);
        Route::get('/zones', [AdminDashboardController::class, 'zones']);
        Route::get('/professionals', [AdminDashboardController::class, 'professionals']);
        Route::post('/professionals/{id}', [AdminDashboardController::class, 'updateProfessional']);
    });
});

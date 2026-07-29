<?php

namespace App\Console\Commands;

use App\Models\ServiceRequest;
use App\Services\DispatchZoneService;
use Illuminate\Console\Command;

/**
 * Cancels requests that were created but never paid.
 *
 * Since the payment step asks the patient to accept the amount before opening
 * Mercado Pago, abandoning the flow leaves a `pending_payment` row behind.
 * Without this, those rows would keep a slot open in the patient's account
 * (only one active request is allowed) and skew the operations metrics.
 */
class ExpireUnpaidBookings extends Command
{
    protected $signature = 'bookings:expire-unpaid {--minutes= : Override the grace period}';

    protected $description = 'Cancel bookings left unpaid past the grace period';

    public function handle(): int
    {
        $minutes = (int) ($this->option('minutes')
            ?: DispatchZoneService::PENDING_PAYMENT_GRACE_MINUTES);

        $expired = ServiceRequest::where('status', 'pending_payment')
            ->where('payment_status', '!=', 'approved')
            ->where('created_at', '<', now()->subMinutes($minutes))
            ->update([
                'status' => 'cancelled',
                'current_step' => 0,
            ]);

        $this->info("Solicitudes sin pago canceladas: $expired (corte: $minutes min)");

        return self::SUCCESS;
    }
}

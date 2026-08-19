<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Banking details on professionals
        Schema::table('professionals', function (Blueprint $table) {
            if (!Schema::hasColumn('professionals', 'rut')) {
                $table->string('rut')->nullable();
            }
            if (!Schema::hasColumn('professionals', 'bank_name')) {
                $table->string('bank_name')->nullable();
            }
            if (!Schema::hasColumn('professionals', 'account_type')) {
                $table->string('account_type')->nullable(); // corriente, vista, rut, ahorro
            }
            if (!Schema::hasColumn('professionals', 'account_number')) {
                $table->string('account_number')->nullable();
            }
            if (!Schema::hasColumn('professionals', 'billing_email')) {
                $table->string('billing_email')->nullable();
            }
        });

        // 2. Professional Payouts table for formal settlements
        if (!Schema::hasTable('professional_payouts')) {
            Schema::create('professional_payouts', function (Blueprint $table) {
                $table->id();
                $table->string('professional_id')->index();
                $table->date('period_start')->nullable();
                $table->date('period_end')->nullable();
                $table->unsignedInteger('gross_total');
                $table->unsignedInteger('retained_total');
                $table->unsignedInteger('net_total');
                $table->unsignedSmallInteger('services_count');
                $table->string('status')->default('pending'); // pending, paid, cancelled
                $table->string('payout_reference')->nullable();
                $table->dateTime('paid_at')->nullable();
                $table->json('bank_snapshot')->nullable();
                $table->timestamps();
            });
        }

        // 3. Traceability fields on professional_earnings
        Schema::table('professional_earnings', function (Blueprint $table) {
            if (!Schema::hasColumn('professional_earnings', 'booking_id')) {
                $table->string('booking_id')->nullable()->index();
            }
            if (!Schema::hasColumn('professional_earnings', 'service_date')) {
                $table->dateTime('service_date')->nullable();
            }
            if (!Schema::hasColumn('professional_earnings', 'period_start')) {
                $table->date('period_start')->nullable();
            }
            if (!Schema::hasColumn('professional_earnings', 'period_end')) {
                $table->date('period_end')->nullable();
            }
            if (!Schema::hasColumn('professional_earnings', 'payout_id')) {
                $table->unsignedBigInteger('payout_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('professional_earnings', function (Blueprint $table) {
            $cols = ['booking_id', 'service_date', 'period_start', 'period_end', 'payout_id'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('professional_earnings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::dropIfExists('professional_payouts');

        Schema::table('professionals', function (Blueprint $table) {
            $cols = ['rut', 'bank_name', 'account_type', 'account_number', 'billing_email'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('professionals', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

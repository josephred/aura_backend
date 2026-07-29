<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            // Normalized dispatch zone (comuna) derived from the address or
            // coordinates. Requests are queued and assigned per zone instead
            // of hunting for one specific doctor.
            $table->string('zone')->nullable()->index();
            // Wait estimate quoted to the patient when the request was placed,
            // so we can compare promise vs. reality later.
            $table->integer('queue_eta_minutes')->nullable();
        });

        Schema::table('professionals', function (Blueprint $table) {
            // Comma-separated list of zones this professional covers. Empty
            // means "available everywhere".
            $table->string('coverage_zones')->nullable();
            // Live duty status used by the dispatcher: disponible|ocupado|desconectado
            $table->string('duty_status')->default('disponible');
        });
    }

    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropColumn(['zone', 'queue_eta_minutes']);
        });

        Schema::table('professionals', function (Blueprint $table) {
            $table->dropColumn(['coverage_zones', 'duty_status']);
        });
    }
};

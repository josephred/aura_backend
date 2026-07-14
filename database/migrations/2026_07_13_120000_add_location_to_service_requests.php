<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            // Patient home coordinates captured on the map when booking.
            $table->double('patient_lat')->nullable();
            $table->double('patient_lng')->nullable();
            // Live position broadcast by the assigned professional.
            $table->double('professional_lat')->nullable();
            $table->double('professional_lng')->nullable();
            $table->timestamp('professional_location_updated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropColumn([
                'patient_lat',
                'patient_lng',
                'professional_lat',
                'professional_lng',
                'professional_location_updated_at',
            ]);
        });
    }
};

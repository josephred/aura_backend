<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('service_requests', 'destination_lat')) {
                $table->double('destination_lat')->nullable();
            }
            if (!Schema::hasColumn('service_requests', 'destination_lng')) {
                $table->double('destination_lng')->nullable();
            }
            if (!Schema::hasColumn('service_requests', 'distance_km')) {
                $table->double('distance_km')->nullable();
            }
            if (!Schema::hasColumn('service_requests', 'transport_fee')) {
                $table->integer('transport_fee')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $columns = ['destination_lat', 'destination_lng', 'distance_km', 'transport_fee'];
            foreach ($columns as $col) {
                if (Schema::hasColumn('service_requests', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

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
            $table->string('payment_preference_id')->nullable();
            $table->text('payment_url')->nullable();
            $table->string('payment_status')->nullable(); // pending | approved | rejected
            $table->string('payment_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropColumn(['payment_preference_id', 'payment_url', 'payment_status', 'payment_id']);
        });
    }
};

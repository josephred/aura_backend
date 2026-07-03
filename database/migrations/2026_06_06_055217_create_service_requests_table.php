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
        Schema::create('service_requests', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->default(1)->constrained('users')->cascadeOnDelete();
            $table->string('service_id');
            $table->foreign('service_id')->references('id')->on('clinical_services')->cascadeOnDelete();
            $table->string('status'); // pending, accepted, en_camino, en_atencion, completed, cancelled
            $table->string('patient_type'); // self, dependent
            $table->string('dependent_id')->nullable();
            $table->foreign('dependent_id')->references('id')->on('dependents')->nullOnDelete();
            $table->string('address_text');
            $table->string('origin_address')->nullable();
            $table->string('destination_address')->nullable();
            $table->string('ambulance_type')->nullable();
            $table->text('symptoms_description')->nullable();
            $table->string('prescription_name')->nullable();
            $table->text('prescription_preview')->nullable();
            $table->string('prescription_file')->nullable();
            $table->string('exam_required')->nullable();
            $table->string('payment_method');
            $table->integer('final_price');
            $table->string('start_time');
            $table->integer('eta_minutes');
            $table->integer('current_step');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_requests');
    }
};

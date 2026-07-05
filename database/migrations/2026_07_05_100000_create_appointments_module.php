<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('professionals', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->string('specialty');
            $table->text('bio')->nullable();
            $table->integer('consultation_price');
            $table->integer('consultation_duration_minutes')->default(30);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('professional_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('professional_id')->index();
            $table->unsignedTinyInteger('day_of_week'); // ISO: 1=lunes .. 7=domingo
            $table->string('start_time', 5); // 'HH:MM'
            $table->string('end_time', 5);
            $table->timestamps();
        });

        Schema::create('appointments', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('professional_id')->index();
            $table->string('dependent_id')->nullable();
            $table->dateTime('scheduled_at')->index();
            $table->integer('duration_minutes');
            $table->text('reason')->nullable();
            $table->string('status')->default('confirmed'); // pending_payment|confirmed|cancelled|completed|no_show
            $table->integer('price');
            $table->string('payment_preference_id')->nullable();
            $table->string('payment_url')->nullable();
            $table->string('payment_status')->nullable();
            $table->string('payment_id')->nullable();
            $table->dateTime('reminder_24h_sent_at')->nullable();
            $table->dateTime('reminder_1h_sent_at')->nullable();
            $table->timestamps();

            $table->index(['professional_id', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('professional_schedules');
        Schema::dropIfExists('professionals');
    }
};

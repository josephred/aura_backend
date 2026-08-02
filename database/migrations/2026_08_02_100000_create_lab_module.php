<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo E — Laboratorio.
 *
 * La toma de muestras deja de despacharse como urgencia inmediata: el
 * laboratorista publica bloques de disponibilidad con antelación y el paciente
 * elige uno. Eso obliga a tres piezas nuevas:
 *
 *  - `lab_schedules`: los bloques publicados. Tabla propia y con fecha
 *    concreta, no semanal como `professional_schedules`, porque la cobertura de
 *    laboratorio se organiza por jornada y sector ("mañana en Providencia"),
 *    tiene cupos simultáneos y cambia semana a semana.
 *  - `lab_results`: los PDF que el laboratorio sube, guardados en el disco
 *    privado y servidos con autorización, nunca por URL pública.
 *  - `professional_earnings`: el libro de retenciones. La plataforma cobra y
 *    después dispersa; sin este registro no hay forma de saber cuánto se le
 *    debe a cada prestador ni de auditar la comisión aplicada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('professional_id')->index();
            // Fecha concreta, no día de la semana: el laboratorista publica la
            // jornada que efectivamente va a cubrir.
            $table->date('date')->index();
            $table->string('start_time', 5); // 'HH:MM'
            $table->string('end_time', 5);
            // Duración de cada cupo dentro del bloque.
            $table->unsignedSmallInteger('slot_minutes')->default(30);
            // Tomas simultáneas que el bloque admite (un laboratorista puede
            // cubrir varias direcciones cercanas dentro de la misma franja).
            $table->unsignedSmallInteger('capacity')->default(1);
            // Sector que cubre el bloque; null significa "toda la cobertura
            // habitual del profesional".
            $table->string('zone')->nullable()->index();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['professional_id', 'date', 'start_time'], 'lab_schedules_block_unique');
        });

        Schema::create('lab_results', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('service_request_id')->index();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('notes')->nullable();
            // Ruta en el disco privado. Nunca una URL pública: son datos de
            // salud y se sirven por /media/lab-results/{id} con autorización.
            $table->string('file_path');
            $table->string('file_name');
            $table->unsignedInteger('file_size')->default(0);
            $table->string('uploaded_by_professional_id')->nullable();
            $table->dateTime('issued_at');
            // Trazabilidad del envío por correo exigido por E.4.
            $table->dateTime('emailed_at')->nullable();
            $table->string('email_error')->nullable();
            $table->timestamps();
        });

        Schema::create('professional_earnings', function (Blueprint $table) {
            $table->id();
            $table->string('professional_id')->index();
            $table->string('source_type'); // service_request | appointment
            $table->string('source_id');
            $table->unsignedInteger('gross_amount');
            // Comisión en puntos base (1250 = 12,5 %). Entero a propósito: con
            // dinero, los flotantes acumulan diferencias de redondeo.
            $table->unsignedSmallInteger('commission_bps');
            $table->unsignedInteger('commission_amount');
            $table->unsignedInteger('net_amount');
            $table->string('status')->default('pending'); // pending|paid
            $table->dateTime('paid_at')->nullable();
            $table->string('payout_reference')->nullable();
            $table->timestamps();

            // Una atención genera un devengo y solo uno, aunque el webhook de
            // pago llegue repetido.
            $table->unique(['source_type', 'source_id'], 'professional_earnings_source_unique');
        });

        Schema::table('service_requests', function (Blueprint $table) {
            // Momento acordado para la toma de muestras.
            $table->dateTime('scheduled_at')->nullable()->index();
            $table->unsignedBigInteger('lab_schedule_id')->nullable()->index();
            // Distingue lo agendado de lo inmediato. Sin esta bandera, una
            // solicitud programada para el jueves sería "la solicitud activa" y
            // quedaría anulada apenas el paciente pida un médico hoy.
            $table->boolean('is_scheduled')->default(false)->index();
            // E.2 — indicaciones del médico o del paciente para el
            // laboratorista (ayuno, orden física, urgencia real).
            $table->text('clinical_notes')->nullable();
        });

        Schema::table('professionals', function (Blueprint $table) {
            // Habilita al prestador para tomar muestras y publicar agenda de
            // laboratorio.
            $table->boolean('provides_lab')->default(false);
            // Comisión propia del prestador; null usa la de la plataforma.
            $table->unsignedSmallInteger('commission_bps')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('professionals', function (Blueprint $table) {
            $table->dropColumn(['provides_lab', 'commission_bps']);
        });

        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropColumn(['scheduled_at', 'lab_schedule_id', 'is_scheduled', 'clinical_notes']);
        });

        Schema::dropIfExists('professional_earnings');
        Schema::dropIfExists('lab_results');
        Schema::dropIfExists('lab_schedules');
    }
};

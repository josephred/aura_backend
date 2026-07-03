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
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('service_request_id');
            $table->foreign('service_request_id')->references('id')->on('service_requests')->cascadeOnDelete();
            $table->string('sender'); // system, provider, patient
            $table->text('text');
            $table->string('timestamp'); // format HH:MM
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};

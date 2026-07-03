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
        Schema::create('past_services', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->default(1)->constrained('users')->cascadeOnDelete();
            $table->string('service_id');
            $table->foreign('service_id')->references('id')->on('clinical_services')->cascadeOnDelete();
            $table->string('service_title');
            $table->string('date');
            $table->string('patient');
            $table->integer('price');
            $table->string('status');
            $table->text('details');
            $table->string('professional');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('past_services');
    }
};

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
        Schema::create('clinical_services', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('title');
            $table->string('short_title');
            $table->string('subtitle');
            $table->text('description');
            $table->integer('base_price');
            $table->string('base_eta');
            $table->boolean('requires_prescription');
            $table->string('icon_name');
            $table->string('warning_info');
            $table->string('placeholder_text');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clinical_services');
    }
};

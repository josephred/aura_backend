<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->string('type')->default('presencial'); // presencial|video
            $table->string('video_room_name')->nullable();
            $table->string('video_room_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn(['type', 'video_room_name', 'video_room_url']);
        });
    }
};

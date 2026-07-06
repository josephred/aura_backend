<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // WebRTC signaling mailbox: offers/answers/ICE candidates exchanged
        // between the patient app and the staff portal during a video call.
        Schema::create('video_signals', function (Blueprint $table) {
            $table->id();
            $table->string('appointment_id')->index();
            $table->string('sender'); // patient|staff
            $table->string('type');   // offer|answer|candidate|ready|hangup
            $table->text('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_signals');
    }
};

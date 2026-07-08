<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Home-visit requests start unassigned (guardia model); accepting
        // one from the portal assigns it to the logged-in professional.
        Schema::table('service_requests', function (Blueprint $table) {
            $table->string('professional_id')->nullable()->index();
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->string('sender_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropColumn('professional_id');
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn('sender_name');
        });
    }
};

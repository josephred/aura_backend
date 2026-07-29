<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a professional work from the mobile app with their own account.
 *
 * The web portal identifies staff through a session; the app authenticates
 * with a Sanctum token bound to a `users` row. This column is the bridge
 * between that account and the clinical identity in `professionals`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('professional_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('professional_id');
        });
    }
};

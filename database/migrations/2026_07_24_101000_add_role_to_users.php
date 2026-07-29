<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // App-side role driving which dashboard the user lands on:
            // patient | dependent_tutor | doctor_provider | operator_admin | ambulance_driver
            $table->string('role')->default('patient')->index();
            // Marks seeded QA accounts so they can be reset or hidden in prod.
            $table->boolean('is_test_account')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'is_test_account']);
        });
    }
};

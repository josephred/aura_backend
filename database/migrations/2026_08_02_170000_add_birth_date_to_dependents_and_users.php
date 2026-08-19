<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dependents', function (Blueprint $table) {
            if (!Schema::hasColumn('dependents', 'birth_date')) {
                $table->date('birth_date')->nullable();
            }
            if (!Schema::hasColumn('dependents', 'last_vaccine_alert_milestone')) {
                $table->unsignedInteger('last_vaccine_alert_milestone')->nullable();
            }
            if (!Schema::hasColumn('dependents', 'last_vaccine_alert_sent_at')) {
                $table->timestamp('last_vaccine_alert_sent_at')->nullable();
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'birth_date')) {
                $table->date('birth_date')->nullable();
            }
            if (!Schema::hasColumn('users', 'age')) {
                $table->unsignedSmallInteger('age')->nullable();
            }
            if (!Schema::hasColumn('users', 'last_preventive_alert_sent_at')) {
                $table->timestamp('last_preventive_alert_sent_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('dependents', function (Blueprint $table) {
            $columns = ['birth_date', 'last_vaccine_alert_milestone', 'last_vaccine_alert_sent_at'];
            foreach ($columns as $col) {
                if (Schema::hasColumn('dependents', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('users', function (Blueprint $table) {
            $columns = ['birth_date', 'age', 'last_preventive_alert_sent_at'];
            foreach ($columns as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

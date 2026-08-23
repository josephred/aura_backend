<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('professionals', 'provides_lab')) {
            Schema::table('professionals', function (Blueprint $table) {
                $table->dropColumn('provides_lab');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('professionals', 'provides_lab')) {
            Schema::table('professionals', function (Blueprint $table) {
                $table->boolean('provides_lab')->default(false);
            });
        }
    }
};

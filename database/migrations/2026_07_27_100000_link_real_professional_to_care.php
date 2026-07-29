<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ties care records to the professional who actually delivered them.
 *
 * Until now the patient app showed a hardcoded doctor and the clinical history
 * saved a name picked at random from a list. Both need a real link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('professionals', function (Blueprint $table) {
            // Contact number the patient sees while the professional is en
            // route. Nullable: existing rows have none yet.
            $table->string('phone')->nullable();
        });

        Schema::table('past_services', function (Blueprint $table) {
            // Who actually attended. The existing `professional` string stays
            // as a snapshot of the display name at the time of care, so the
            // history keeps reading correctly even if the person is renamed
            // or deactivated later.
            $table->string('professional_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('professionals', function (Blueprint $table) {
            $table->dropColumn('phone');
        });

        Schema::table('past_services', function (Blueprint $table) {
            $table->dropColumn('professional_id');
        });
    }
};

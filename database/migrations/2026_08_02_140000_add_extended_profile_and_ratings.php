<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('professionals', function (Blueprint $table) {
            if (!Schema::hasColumn('professionals', 'registration_number')) {
                $table->string('registration_number')->nullable()->comment('Registro Superintendencia de Salud');
            }
            if (!Schema::hasColumn('professionals', 'years_of_experience')) {
                $table->unsignedSmallInteger('years_of_experience')->default(5);
            }
            if (!Schema::hasColumn('professionals', 'photo_url')) {
                $table->string('photo_url')->nullable();
            }
            if (!Schema::hasColumn('professionals', 'rating_avg')) {
                $table->decimal('rating_avg', 3, 2)->default(5.00);
            }
            if (!Schema::hasColumn('professionals', 'rating_count')) {
                $table->unsignedInteger('rating_count')->default(0);
            }
        });

        if (!Schema::hasTable('service_ratings')) {
            Schema::create('service_ratings', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('booking_id')->index();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('professional_id')->index();
                $table->unsignedTinyInteger('rating'); // 1 a 5 estrellas
                $table->text('feedback')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('service_ratings');
    }
};

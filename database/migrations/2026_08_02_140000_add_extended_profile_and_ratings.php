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
                $table->unsignedSmallInteger('years_of_experience')->nullable();
            }
            if (!Schema::hasColumn('professionals', 'photo_url')) {
                $table->string('photo_url')->nullable();
            }
            if (!Schema::hasColumn('professionals', 'rating_avg')) {
                $table->decimal('rating_avg', 3, 2)->nullable();
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

                $table->unique(['booking_id', 'user_id']);
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                $table->foreign('booking_id')->references('id')->on('service_requests')->onDelete('cascade');
                $table->foreign('professional_id')->references('id')->on('professionals')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('service_ratings');

        Schema::table('professionals', function (Blueprint $table) {
            $columns = ['registration_number', 'years_of_experience', 'photo_url', 'rating_avg', 'rating_count'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('professionals', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

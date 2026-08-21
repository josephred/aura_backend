<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->text('description');
            $table->integer('monthly_price');
            $table->integer('included_consultations')->default(1);
            $table->integer('discount_percentage')->default(15);
            $table->json('features')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('user_subscriptions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('plan_id')->index();
            $table->string('status')->default('pending'); // pending|active|cancelled|past_due
            $table->string('mercadopago_preapproval_id')->nullable()->index();
            $table->dateTime('starts_at');
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('next_billing_date')->nullable();
            $table->integer('consultations_used')->default(0);
            $table->boolean('auto_renew')->default(true);
            $table->string('payment_method_id')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('plan_id')->references('id')->on('subscription_plans')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_subscriptions');
        Schema::dropIfExists('subscription_plans');
    }
};

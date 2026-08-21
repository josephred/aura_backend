<?php

namespace Tests\Feature;

use App\Models\ClinicalService;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserSubscription;
use Database\Seeders\ClinicalServicesSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ClinicalServicesSeeder::class);
        $this->seed(SubscriptionPlanSeeder::class);
    }

    public function test_guest_can_list_active_subscription_plans(): void
    {
        $response = $this->getJson('/api/subscriptions/plans');

        $response->assertOk()
            ->assertJsonCount(3)
            ->assertJsonFragment(['id' => 'plan_individual'])
            ->assertJsonFragment(['id' => 'plan_familiar'])
            ->assertJsonFragment(['id' => 'plan_senior']);
    }

    public function test_user_without_subscription_returns_has_subscription_false(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/subscriptions/current');

        $response->assertOk()
            ->assertJson(['has_subscription' => false, 'subscription' => null]);
    }

    public function test_user_can_subscribe_to_plan(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/subscriptions/subscribe', [
            'plan_id' => 'plan_familiar',
        ]);

        $response->assertCreated()
            ->assertJsonPath('subscription.plan_id', 'plan_familiar');

        $this->assertDatabaseHas('user_subscriptions', [
            'user_id' => $user->id,
            'plan_id' => 'plan_familiar',
        ]);
    }

    public function test_user_can_cancel_active_subscription(): void
    {
        $user = User::factory()->create();
        UserSubscription::create([
            'id' => 'sub_test_cancel',
            'user_id' => $user->id,
            'plan_id' => 'plan_individual',
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => now()->addMonth(),
            'auto_renew' => true,
        ]);

        $response = $this->actingAs($user)->postJson('/api/subscriptions/cancel');

        $response->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('user_subscriptions', [
            'id' => 'sub_test_cancel',
            'status' => 'cancelled',
            'auto_renew' => false,
        ]);
    }

    public function test_active_subscription_with_included_consultations_sets_price_to_zero(): void
    {
        $user = User::factory()->create();
        UserSubscription::create([
            'id' => 'sub_test_included',
            'user_id' => $user->id,
            'plan_id' => 'plan_individual', // 1 consultation included
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => now()->addMonth(),
            'consultations_used' => 0,
        ]);

        $response = $this->actingAs($user)->postJson('/api/bookings', [
            'service_id' => 'medico',
            'patient_type' => 'self',
            'address_text' => 'Av Providencia 1234, Providencia',
            'symptoms_description' => 'Fiebre alta persistente y dolor corporal intenso',
            'eta_minutes' => 30,
        ]);

        $response->assertCreated()
            ->assertJsonPath('final_price', 0)
            ->assertJsonPath('status', 'accepted'); // price 0 activates booking directly in non-prod

        $this->assertDatabaseHas('user_subscriptions', [
            'id' => 'sub_test_included',
            'consultations_used' => 1,
        ]);
    }

    public function test_active_subscription_applies_discount_percentage_when_consultations_used(): void
    {
        $user = User::factory()->create();
        UserSubscription::create([
            'id' => 'sub_test_discount',
            'user_id' => $user->id,
            'plan_id' => 'plan_individual', // 15% discount
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => now()->addMonth(),
            'consultations_used' => 1, // exhausted 1 included consultation
        ]);

        $service = ClinicalService::find('medico');
        $base = (int) $service->base_price;
        $surcharge = intdiv($base * 1500, 10000);
        $totalExpected = $base + $surcharge;
        $discountAmount = intdiv($totalExpected * 15, 100);
        $discountedPrice = $totalExpected - $discountAmount;

        $response = $this->actingAs($user)->postJson('/api/bookings', [
            'service_id' => 'medico',
            'patient_type' => 'self',
            'address_text' => 'Av Providencia 1234, Providencia',
            'symptoms_description' => 'Fiebre alta persistente y dolor corporal intenso',
            'eta_minutes' => 30,
        ]);

        $response->assertCreated()
            ->assertJsonPath('final_price', $discountedPrice);
    }
}

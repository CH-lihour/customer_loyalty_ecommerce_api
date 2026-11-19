<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\LoyaltyTransaction;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReferralTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_list_referrals_with_totals_and_filters(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create());
        $sokha = Customer::factory()->create(['name' => 'Sokha Meas', 'referral_code' => 'SOKHA123']);
        $dara = Customer::factory()->create(['name' => 'Dara Kim']);
        $vannak = Customer::factory()->create(['name' => 'Vannak Chea']);
        Referral::factory()->create(['referrer_id' => $sokha->id, 'referred_id' => $dara->id, 'is_completed' => true]);
        Referral::factory()->create(['referrer_id' => $sokha->id, 'referred_id' => $vannak->id]);
        Referral::factory()->create();

        $this->getJson('/api/backend/referrals')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('stats.total', 3)
            ->assertJsonPath('stats.converted', 1)
            ->assertJsonPath('stats.pending', 2)
            ->assertJsonPath('stats.points_paid_out', Referral::REFERRER_POINTS + Referral::REFERRED_POINTS);

        $count = fn (string $query) => $this->getJson("/api/backend/referrals?{$query}")->assertOk()->json('meta.total');

        $this->assertSame(2, $count('search=sokha'));
        $this->assertSame(2, $count('search=sokha123'));
        $this->assertSame(1, $count('search=VANNAK'));
        $this->assertSame(1, $count('status=converted'));
        $this->assertSame(2, $count('status=pending'));
        $this->assertSame(1, $count('search=sokha&status=pending'));

        $this->getJson('/api/backend/referrals?status=done')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_marking_converted_pays_both_customers_once(): void
    {
        $staff = User::factory()->superAdmin()->create();
        Sanctum::actingAs($staff);
        $referral = Referral::factory()->create();

        $this->patchJson("/api/backend/referrals/{$referral->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.is_completed', true)
            ->assertJsonPath('data.completer.id', $staff->id);

        $this->assertDatabaseHas('customers', ['id' => $referral->referrer_id, 'points_balance' => Referral::REFERRER_POINTS, 'qualifying_points' => 0]);
        $this->assertDatabaseHas('customers', ['id' => $referral->referred_id, 'points_balance' => Referral::REFERRED_POINTS]);
        $this->assertDatabaseHas('loyalty_transactions', [
            'customer_id' => $referral->referrer_id,
            'type' => LoyaltyTransaction::TYPE_REFERRAL,
            'points' => Referral::REFERRER_POINTS,
            'created_by' => $staff->id,
        ]);
        $this->assertDatabaseCount('activity_logs', 2);

        $this->patchJson("/api/backend/referrals/{$referral->id}/complete")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['referral' => 'already']);
        $this->assertDatabaseCount('loyalty_transactions', 2);
    }

    public function test_staff_without_the_referrals_page_are_refused(): void
    {
        Sanctum::actingAs(User::factory()->withPages(['points'])->create());
        $referral = Referral::factory()->create();

        $this->getJson('/api/backend/referrals')->assertForbidden();
        $this->patchJson("/api/backend/referrals/{$referral->id}/complete")->assertForbidden();
        $this->assertFalse($referral->fresh()->is_completed);
    }

    public function test_customer_sees_their_code_and_only_their_referrals(): void
    {
        $sokha = Customer::factory()->create(['referral_code' => 'SOKHA123']);
        $dara = Customer::factory()->create(['name' => 'Dara Kim', 'email' => 'dara@example.com']);
        $vannak = Customer::factory()->create(['name' => 'Vannak Chea']);
        Referral::factory()->create(['referrer_id' => $sokha->id, 'referred_id' => $dara->id, 'is_completed' => true]);
        Referral::factory()->create(['referrer_id' => $sokha->id, 'referred_id' => $vannak->id]);
        Referral::factory()->create();
        Sanctum::actingAs($sokha, guard: 'customer');

        $response = $this->getJson('/api/referrals')
            ->assertOk()
            ->assertJsonPath('data.referral_code', 'SOKHA123')
            ->assertJsonPath('data.referrer_points', Referral::REFERRER_POINTS)
            ->assertJsonPath('data.referred_points', Referral::REFERRED_POINTS)
            ->assertJsonPath('data.stats.total', 2)
            ->assertJsonPath('data.stats.converted', 1)
            ->assertJsonPath('data.stats.points_earned', Referral::REFERRER_POINTS)
            ->assertJsonCount(2, 'data.referrals');

        $this->assertEqualsCanonicalizing(['Dara Kim', 'Vannak Chea'], array_column($response->json('data.referrals'), 'name'));
        $this->assertStringNotContainsString('dara@example.com', $response->getContent());

        $this->getJson('/api/profile')->assertOk()->assertJsonPath('data.referral_code', 'SOKHA123');
    }

    public function test_guests_cannot_see_referrals(): void
    {
        $this->getJson('/api/referrals')->assertUnauthorized();
        $this->getJson('/api/backend/referrals')->assertUnauthorized();
    }
}

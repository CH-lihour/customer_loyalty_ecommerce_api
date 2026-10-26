<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Tier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CustomerAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_register_with_required_fields_into_entry_tier(): void
    {
        Tier::factory()->create(['name' => 'Silver', 'min_points' => 1000]);
        $bronze = Tier::factory()->create(['name' => 'Bronze', 'min_points' => 0]);

        $response = $this->postJson('/api/register', [
            'name' => 'Sokha Chan',
            'email' => ' Sokha@Example.com ',
            'password' => 'secret123',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['message', 'token', 'token_type', 'customer' => ['id', 'email', 'referral_code']])
            ->assertJsonPath('customer.email', 'sokha@example.com')
            ->assertJsonPath('customer.tier.id', $bronze->id)
            ->assertJsonPath('customer.points_balance', 0)
            ->assertJsonPath('customer.avatar', null)
            ->assertJsonMissingPath('customer.password');

        $customer = Customer::sole();
        $this->assertTrue(Hash::check('secret123', $customer->password));
        $this->assertSame(8, strlen($customer->referral_code));
        $this->assertDatabaseCount('referrals', 0);

        $this->withToken($response->json('token'))
            ->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('data.email', 'sokha@example.com');
    }

    public function test_customer_can_register_with_avatar_and_referral_code(): void
    {
        Storage::fake('public');
        $referrer = Customer::factory()->create(['referral_code' => 'FRIEND01']);

        $this->post('/api/register', [
            'name' => 'Dara Kim',
            'name_kh' => 'ដារា',
            'email' => 'dara@example.com',
            'password' => 'secret123',
            'phone' => '012345678',
            'province' => 'Siem Reap',
            'avatar' => UploadedFile::fake()->image('me.jpg'),
            'referral_code' => 'friend01',
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('customer.name_kh', 'ដារា')
            ->assertJsonPath('customer.province', 'Siem Reap');

        $customer = Customer::where('email', 'dara@example.com')->sole();
        Storage::disk('public')->assertExists($customer->avatar);
        $this->assertNotSame('FRIEND01', $customer->referral_code);
        $this->assertDatabaseHas('referrals', [
            'referrer_id' => $referrer->id,
            'referred_id' => $customer->id,
            'is_completed' => false,
        ]);
    }

    public function test_register_validates_input(): void
    {
        Customer::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/register', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password']);

        $this->postJson('/api/register', [
            'name' => 'Someone',
            'email' => 'taken@example.com',
            'password' => 'short',
            'referral_code' => 'NOPE0000',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password', 'referral_code']);

        $this->assertDatabaseCount('customers', 1);
    }

    public function test_register_is_unavailable_without_tiers(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Sokha Chan',
            'email' => 'sokha@example.com',
            'password' => 'secret123',
        ])->assertServiceUnavailable();

        $this->assertDatabaseCount('customers', 0);
    }

    public function test_customer_can_login_with_valid_credentials(): void
    {
        $customer = Customer::factory()->create(['password' => 'secret123']);

        $response = $this->postJson('/api/login', [
            'email' => $customer->email,
            'password' => 'secret123',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['message', 'token', 'token_type', 'customer' => ['id', 'email']])
            ->assertJsonMissingPath('customer.password');

        $this->withToken($response->json('token'))
            ->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('data.email', $customer->email);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $customer = Customer::factory()->create(['password' => 'secret123']);

        $this->postJson('/api/login', [
            'email' => $customer->email,
            'password' => 'wrong-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_login_requires_email_and_password(): void
    {
        $this->postJson('/api/login', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_inactive_customer_cannot_login(): void
    {
        $customer = Customer::factory()->inactive()->create(['password' => 'secret123']);

        $this->postJson('/api/login', [
            'email' => $customer->email,
            'password' => 'secret123',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_staff_credentials_cannot_login_as_customer(): void
    {
        $user = User::factory()->create(['password' => 'secret123']);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'secret123',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_customer_token_cannot_access_staff_routes(): void
    {
        $token = $this->login()->json('token');

        $this->withToken($token)->getJson('/api/backend/profile')->assertUnauthorized();
    }

    public function test_staff_token_cannot_access_customer_routes(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/profile')->assertUnauthorized();
    }

    public function test_customer_can_logout(): void
    {
        $token = $this->login()->json('token');

        $this->withToken($token)->postJson('/api/logout')->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    private function login(): TestResponse
    {
        $customer = Customer::factory()->create(['password' => 'secret123']);

        return $this->postJson('/api/login', [
            'email' => $customer->email,
            'password' => 'secret123',
        ])->assertOk();
    }
}

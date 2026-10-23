<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CustomerAuthTest extends TestCase
{
    use RefreshDatabase;

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

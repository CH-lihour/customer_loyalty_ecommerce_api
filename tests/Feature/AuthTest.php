<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create(['password' => 'secret123']);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'secret123',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['message', 'token', 'token_type', 'user' => ['id', 'email']]);

        $this->withToken($response->json('token'))
            ->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = User::factory()->create(['password' => 'secret123']);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_login_requires_email_and_password(): void
    {
        $this->postJson('/api/login', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_unauthenticated_api_request_returns_json_401_without_accept_header(): void
    {
        $this->post('/api/logout')
            ->assertUnauthorized()
            ->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_token_stays_valid_while_it_keeps_being_used(): void
    {
        config(['sanctum.idle_timeout' => 60]);
        $token = $this->login()->json('token');

        // Two requests 50 minutes apart: 100 minutes total, but never idle for 60
        foreach ([50, 50] as $minutes) {
            $this->travel($minutes)->minutes();
            $this->app['auth']->forgetGuards();
            $this->withToken($token)->getJson('/api/profile')->assertOk();
        }
    }

    public function test_token_expires_after_idle_timeout(): void
    {
        config(['sanctum.idle_timeout' => 60]);
        $token = $this->login()->json('token');

        $this->travel(61)->minutes();

        $this->withToken($token)->getJson('/api/profile')->assertUnauthorized();
    }

    public function test_user_can_logout(): void
    {
        $token = $this->login()->json('token');

        $this->withToken($token)->postJson('/api/logout')->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    private function login(): TestResponse
    {
        $user = User::factory()->create(['password' => 'secret123']);

        return $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'secret123',
        ])->assertOk();
    }
}

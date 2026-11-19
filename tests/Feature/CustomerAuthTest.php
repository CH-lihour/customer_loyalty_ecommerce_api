<?php

namespace Tests\Feature;

use App\Mail\RegistrationOtpMail;
use App\Models\Customer;
use App\Models\CustomerOtp;
use App\Models\Tier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

class CustomerAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

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
            ->assertJsonPath('requires_verification', true)
            ->assertJsonPath('code_sent', true)
            ->assertJsonPath('email', 'sokha@example.com')
            ->assertJsonMissingPath('token');

        $customer = Customer::sole();
        $this->assertTrue(Hash::check('secret123', $customer->password));
        $this->assertSame(8, strlen($customer->referral_code));
        $this->assertSame($bronze->id, $customer->tier_id);
        $this->assertNull($customer->email_verified_at);
        $this->assertDatabaseCount('referrals', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $code = $this->sentCode('sokha@example.com');
        $this->assertDatabaseMissing('customer_otps', ['code_hash' => $code]);

        $verified = $this->postJson('/api/register/verify', ['email' => 'Sokha@example.com', 'code' => $code])
            ->assertOk()
            ->assertJsonStructure(['message', 'token', 'token_type', 'customer' => ['id', 'email', 'referral_code']])
            ->assertJsonPath('customer.email', 'sokha@example.com')
            ->assertJsonPath('customer.tier.id', $bronze->id)
            ->assertJsonPath('customer.points_balance', 0)
            ->assertJsonPath('customer.avatar', null)
            ->assertJsonMissingPath('customer.password');

        $this->assertNotNull($customer->refresh()->email_verified_at);
        $this->assertDatabaseCount('customer_otps', 0);

        $this->withToken($verified->json('token'))
            ->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('data.email', 'sokha@example.com');
    }

    public function test_wrong_code_is_rejected_and_locks_after_max_attempts(): void
    {
        $this->registerSokha();
        $code = $this->sentCode('sokha@example.com');
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->postJson('/api/register/verify', ['email' => 'sokha@example.com', 'code' => $wrong])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code')
            ->assertJsonPath('errors.code.0', 'That code is not correct. 4 tries left.');

        foreach (range(1, CustomerOtp::MAX_ATTEMPTS - 1) as $ignored) {
            $this->postJson('/api/register/verify', ['email' => 'sokha@example.com', 'code' => $wrong])->assertUnprocessable();
        }

        $this->postJson('/api/register/verify', ['email' => 'sokha@example.com', 'code' => $code])
            ->assertUnprocessable()
            ->assertJsonPath('errors.code.0', 'This code has expired. Please request a new one.');

        $this->assertNull(Customer::sole()->email_verified_at);
    }

    public function test_expired_code_is_rejected(): void
    {
        $this->registerSokha();
        $code = $this->sentCode('sokha@example.com');

        $this->travel(CustomerOtp::EXPIRES_IN_MINUTES + 1)->minutes();

        $this->postJson('/api/register/verify', ['email' => 'sokha@example.com', 'code' => $code])
            ->assertUnprocessable()
            ->assertJsonPath('errors.code.0', 'This code has expired. Please request a new one.');
    }

    public function test_verify_validates_input(): void
    {
        $this->postJson('/api/register/verify', ['email' => 'nope', 'code' => '12ab'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'code']);

        $this->postJson('/api/register/verify', ['email' => 'nobody@example.com', 'code' => '123456'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    public function test_resend_waits_for_cooldown_and_replaces_the_code(): void
    {
        $this->registerSokha();
        $firstCode = $this->sentCode('sokha@example.com');

        $this->postJson('/api/register/resend', ['email' => 'sokha@example.com'])
            ->assertTooManyRequests()
            ->assertJsonPath('resend_in_seconds', CustomerOtp::RESEND_COOLDOWN_SECONDS);

        Mail::assertSentCount(1);

        $this->travel(CustomerOtp::RESEND_COOLDOWN_SECONDS)->seconds();

        $this->postJson('/api/register/resend', ['email' => 'sokha@example.com'])->assertOk();

        Mail::assertSentCount(2);
        $secondCode = Mail::sent(RegistrationOtpMail::class)->last()->code;

        if ($firstCode !== $secondCode) {
            $this->postJson('/api/register/verify', ['email' => 'sokha@example.com', 'code' => $firstCode])->assertUnprocessable();
        }

        $this->postJson('/api/register/verify', ['email' => 'sokha@example.com', 'code' => $secondCode])->assertOk();
    }

    public function test_resend_is_refused_for_verified_or_unknown_emails(): void
    {
        $customer = Customer::factory()->create();

        $this->postJson('/api/register/resend', ['email' => $customer->email])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/register/resend', ['email' => 'nobody@example.com'])->assertUnprocessable()->assertJsonValidationErrors('email');

        Mail::assertNothingSent();
    }

    public function test_registration_succeeds_when_the_code_email_fails(): void
    {
        Tier::factory()->create(['min_points' => 0]);
        Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP down'));

        $this->postJson('/api/register', [
            'name' => 'Sokha Chan',
            'email' => 'sokha@example.com',
            'password' => 'secret123',
        ])->assertCreated()->assertJsonPath('code_sent', false);

        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('customer_otps', 0);
    }

    public function test_unverified_customer_cannot_login(): void
    {
        $customer = Customer::factory()->unverified()->create(['password' => 'secret123']);

        $this->postJson('/api/login', [
            'email' => $customer->email,
            'password' => 'secret123',
        ])->assertForbidden()->assertJsonPath('requires_verification', true);

        $this->assertDatabaseCount('personal_access_tokens', 0);
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
            ->assertCreated();

        $customer = Customer::where('email', 'dara@example.com')->sole();
        $this->assertSame('ដារា', $customer->name_kh);
        $this->assertSame('Siem Reap', $customer->province);
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

    /**
     * Register the sample customer used by the code tests.
     */
    private function registerSokha(): void
    {
        Tier::factory()->create(['min_points' => 0]);

        $this->postJson('/api/register', [
            'name' => 'Sokha Chan',
            'email' => 'sokha@example.com',
            'password' => 'secret123',
        ])->assertCreated();
    }

    /**
     * The code in the last verification email sent to the address.
     */
    private function sentCode(string $email): string
    {
        $mail = Mail::sent(RegistrationOtpMail::class, fn (RegistrationOtpMail $mail) => $mail->hasTo($email))->last();
        $this->assertNotNull($mail, "No verification code was emailed to {$email}.");
        $this->assertMatchesRegularExpression('/^\d{6}$/', $mail->code);

        return $mail->code;
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

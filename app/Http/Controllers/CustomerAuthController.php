<?php

namespace App\Http\Controllers;

use App\Http\Requests\CustomerRegisterRequest;
use App\Models\Customer;
use App\Models\CustomerOtp;
use App\Models\Referral;
use App\Models\Tier;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

class CustomerAuthController extends Controller
{
    /**
     * The disk uploaded customer avatars are stored on.
     */
    private const AVATAR_DISK = 'public';

    /**
     * Register a customer in the entry tier, record who referred them, and email them a verification code.
     *
     * No token is issued yet: the customer signs in by confirming the code with `verifyOtp`.
     */
    public function register(CustomerRegisterRequest $request): JsonResponse
    {
        return $this->attempt('Could not create your account. Please try again.', function () use ($request): JsonResponse {
            $data = $request->safe()->except(['avatar', 'referral_code', 'device_name']);
            $entryTier = Tier::orderBy('min_points')->first();

            abort_if(! $entryTier, 503, 'Registration is unavailable until loyalty tiers are set up.');

            $avatarPath = $request->file('avatar')?->store('customers/avatars', self::AVATAR_DISK) ?: null;

            try {
                $customer = DB::transaction(function () use ($data, $entryTier, $avatarPath, $request): Customer {
                    $customer = Customer::create([
                        ...$data,
                        'tier_id' => $entryTier->id,
                        'avatar' => $avatarPath,
                        'referral_code' => $this->uniqueReferralCode(),
                    ]);

                    $referralCode = $request->validated('referral_code');
                    $referrerId = $referralCode ? Customer::where('referral_code', $referralCode)->value('id') : null;

                    // The code was valid during validation; skip the referral if that customer is gone since.
                    if ($referrerId) {
                        Referral::create([
                            'referrer_id' => $referrerId,
                            'referred_id' => $customer->id,
                        ]);
                    }

                    return $customer;
                });
            } catch (Throwable $exception) {
                // Nothing references the uploaded avatar once the customer row is rolled back.
                if ($avatarPath) {
                    Storage::disk(self::AVATAR_DISK)->delete($avatarPath);
                }

                throw $exception;
            }

            try {
                $customer->sendVerificationCode();
                $codeSent = true;
            } catch (Throwable $exception) {
                // The account exists either way; the customer can ask for another code.
                report($exception);
                $codeSent = false;
            }

            return response()->json([
                'message' => $codeSent
                    ? "We sent a 6-digit code to {$customer->email}. Enter it to finish signing up."
                    : 'Your account was created, but we could not send the code. Please tap resend.',
                'requires_verification' => true,
                'code_sent' => $codeSent,
                'email' => $customer->email,
                'expires_in_minutes' => CustomerOtp::EXPIRES_IN_MINUTES,
                'resend_in_seconds' => CustomerOtp::RESEND_COOLDOWN_SECONDS,
            ], 201);
        });
    }

    /**
     * Authenticate a customer and issue a Sanctum API token.
     */
    public function login(Request $request): JsonResponse
    {
        return $this->attempt('Could not sign in. Please try again.', function () use ($request): JsonResponse {
            $credentials = $request->validate([
                'email' => ['required', 'email'],
                'password' => ['required', 'string'],
                'device_name' => ['nullable', 'string', 'max:255'],
            ]);

            $customer = Customer::where('email', $credentials['email'])->first(['id', 'name', 'email', 'email_verified_at', 'phone', 'password', 'is_active']);

            if (! $customer || ! $customer->password || ! Hash::check($credentials['password'], $customer->password)) {
                throw ValidationException::withMessages([
                    'email' => ['The provided credentials are incorrect.'],
                ]);
            }

            if (! $customer->is_active) {
                throw ValidationException::withMessages([
                    'email' => ['This account has been deactivated.'],
                ]);
            }

            if (! $customer->hasVerifiedEmail()) {
                return response()->json([
                    'message' => 'Please verify your email before signing in.',
                    'requires_verification' => true,
                    'email' => $customer->email,
                ], 403);
            }

            $name = $credentials['device_name'] ?? $request->userAgent() ?? 'api';
            $token = $customer->createToken(Str::limit($name, 255, ''))->plainTextToken;

            return response()->json([
                'message' => 'Login successful',
                'token' => $token,
                'token_type' => 'Bearer',
                'customer' => $customer->only(['id', 'name', 'email', 'phone']),
            ]);
        });
    }

    /**
     * Confirm the emailed code, mark the email as verified and issue a Sanctum API token.
     */
    public function verifyOtp(Request $request): JsonResponse
    {
        return $this->attempt('Could not verify the code. Please try again.', function () use ($request): JsonResponse {
            $request->merge(['email' => is_string($request->input('email')) ? mb_strtolower(trim($request->input('email'))) : $request->input('email')]);

            $data = $request->validate([
                'email' => ['required', 'email'],
                'code' => ['required', 'string', 'digits:'.CustomerOtp::CODE_LENGTH],
                'device_name' => ['nullable', 'string', 'max:255'],
            ]);

            $customer = Customer::with(['otp', 'tier'])->where('email', $data['email'])->first();
            $otp = $customer?->otp;

            if (! $customer || $customer->hasVerifiedEmail() || ! $otp) {
                throw ValidationException::withMessages([
                    'code' => ['There is no code waiting for this email. Please request a new one.'],
                ]);
            }

            if ($otp->isExpired()) {
                throw ValidationException::withMessages([
                    'code' => ['This code has expired. Please request a new one.'],
                ]);
            }

            if (! $otp->matches($data['code'])) {
                $otp->increment('attempts');
                $left = CustomerOtp::MAX_ATTEMPTS - $otp->attempts;

                throw ValidationException::withMessages([
                    'code' => [$left > 0
                        ? "That code is not correct. {$left} ".($left === 1 ? 'try' : 'tries').' left.'
                        : 'Too many wrong codes. Please request a new one.'],
                ]);
            }

            DB::transaction(function () use ($customer, $otp): void {
                $customer->markEmailAsVerified();
                $otp->delete();
            });

            $name = $data['device_name'] ?? $request->userAgent() ?? 'api';
            $token = $customer->createToken(Str::limit($name, 255, ''))->plainTextToken;

            return response()->json([
                'message' => 'Email verified. Registration complete.',
                'token' => $token,
                'token_type' => 'Bearer',
                'customer' => [
                    ...$customer->only(['id', 'name', 'name_kh', 'email', 'phone', 'province', 'referral_code', 'points_balance']),
                    'avatar' => $customer->avatar ? url('storage/'.$customer->avatar) : null,
                    'tier' => $customer->tier?->only(['id', 'name']),
                ],
            ]);
        });
    }

    /**
     * Email a new verification code to a customer who has not verified yet, at most once per cooldown.
     */
    public function resendOtp(Request $request): JsonResponse
    {
        return $this->attempt('Could not send a new code. Please try again.', function () use ($request): JsonResponse {
            $request->merge(['email' => is_string($request->input('email')) ? mb_strtolower(trim($request->input('email'))) : $request->input('email')]);

            $data = $request->validate([
                'email' => ['required', 'email'],
            ]);

            $customer = Customer::with('otp')->where('email', $data['email'])->first();

            if (! $customer || $customer->hasVerifiedEmail()) {
                throw ValidationException::withMessages([
                    'email' => ['There is no account waiting for verification with this email.'],
                ]);
            }

            $wait = $customer->otp?->secondsUntilResend() ?? 0;

            if ($wait > 0) {
                return response()->json([
                    'message' => "Please wait {$wait} seconds before asking for another code.",
                    'resend_in_seconds' => $wait,
                ], 429);
            }

            $customer->sendVerificationCode();

            return response()->json([
                'message' => "We sent a new code to {$customer->email}.",
                'expires_in_minutes' => CustomerOtp::EXPIRES_IN_MINUTES,
                'resend_in_seconds' => CustomerOtp::RESEND_COOLDOWN_SECONDS,
            ]);
        });
    }

    /**
     * Revoke the token used for the current request.
     */
    public function logout(Request $request): JsonResponse
    {
        return $this->attempt('Could not sign out. Please try again.', function () use ($request): JsonResponse {
            $customer = $request->user();

            if (! $customer) {
                throw new AuthenticationException;
            }

            $token = $customer->currentAccessToken();

            if ($token instanceof PersonalAccessToken) {
                $token->delete();
            }

            return response()->json(['message' => 'Logged out']);
        });
    }

    /**
     * Generate a referral code no other customer has.
     */
    private function uniqueReferralCode(): string
    {
        do {
            $code = Str::upper(Str::random(8));
        } while (Customer::where('referral_code', $code)->exists());

        return $code;
    }
}

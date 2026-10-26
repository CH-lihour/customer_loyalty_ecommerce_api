<?php

namespace App\Http\Controllers;

use App\Http\Requests\CustomerRegisterRequest;
use App\Models\Customer;
use App\Models\Referral;
use App\Models\Tier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerAuthController extends Controller
{
    /**
     * The disk uploaded customer avatars are stored on.
     */
    private const AVATAR_DISK = 'public';

    /**
     * Register a customer in the entry tier, record who referred them, and issue a Sanctum API token.
     */
    public function register(CustomerRegisterRequest $request): JsonResponse
    {
        $data = $request->safe()->except(['avatar', 'referral_code', 'device_name']);
        $entryTier = Tier::orderBy('min_points')->first();

        abort_if(! $entryTier, 503, 'Registration is unavailable until loyalty tiers are set up.'); 

        $avatarPath = $request->file('avatar')?->store('customers/avatars', self::AVATAR_DISK);

        $customer = DB::transaction(function () use ($data, $entryTier, $avatarPath, $request): Customer {
            $customer = Customer::create([
                ...$data,
                'tier_id' => $entryTier->id,
                'avatar' => $avatarPath,
                'referral_code' => $this->uniqueReferralCode(),
            ]);

            if ($referralCode = $request->validated('referral_code')) {
                Referral::create([
                    'referrer_id' => Customer::where('referral_code', $referralCode)->value('id'),
                    'referred_id' => $customer->id,
                ]);
            }

            return $customer;
        });

        $name = $request->validated('device_name') ?? $request->userAgent() ?? 'api';
        $token = $customer->createToken(Str::limit($name, 255, ''))->plainTextToken;

        return response()->json([
            'message' => 'Registration successful',
            'token' => $token,
            'token_type' => 'Bearer',
            'customer' => [
                ...$customer->refresh()->only(['id', 'name', 'name_kh', 'email', 'phone', 'province', 'referral_code', 'points_balance']),
                'avatar' => $avatarPath ? url('storage/'.$avatarPath) : null,
                'tier' => $entryTier->only(['id', 'name']),
            ],
        ], 201);
    }

    /**
     * Authenticate a customer and issue a Sanctum API token.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        $customer = Customer::where('email', $credentials['email'])->first(['id', 'name', 'email', 'phone', 'password', 'is_active']);

        if (! $customer || ! Hash::check($credentials['password'], $customer->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $customer->is_active) {
            throw ValidationException::withMessages([
                'email' => ['This account has been deactivated.'],
            ]);
        }

        $name = $credentials['device_name'] ?? $request->userAgent() ?? 'api';
        $token = $customer->createToken(Str::limit($name, 255, ''))->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'token' => $token,
            'token_type' => 'Bearer',
            'customer' => $customer->only(['id', 'name', 'email', 'phone']),
        ]);
    }

    /**
     * Revoke the token used for the current request.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out']);
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

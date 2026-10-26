<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    /**
     * Authenticate a user and issue a Sanctum API token.
     */
    public function login(Request $request): JsonResponse
    {
        return $this->attempt('Could not sign in. Please try again.', function () use ($request): JsonResponse {
            $credentials = $request->validate([
                'email' => ['required', 'email'],
                'password' => ['required', 'string'],
                'device_name' => ['nullable', 'string', 'max:255'],
            ]);

            $user = User::where('email', $credentials['email'])->first(['id', 'name', 'email', 'password', 'is_active']);

            if (! $user || ! $user->password || ! Hash::check($credentials['password'], $user->password)) {
                throw ValidationException::withMessages([
                    'email' => ['The provided credentials are incorrect.'],
                ]);
            }

            if (! $user->is_active) {
                throw ValidationException::withMessages([
                    'email' => ['This staff account has been disabled.'],
                ]);
            }

            $name = $credentials['device_name'] ?? $request->userAgent() ?? 'api';
            $token = $user->createToken(Str::limit($name, 255, ''))->plainTextToken;

            return response()->json([
                'message' => 'Login successful',
                'token' => $token,
                'token_type' => 'Bearer',
                'user' => $user,
            ]);
        });
    }

    /**
     * Revoke the token used for the current request.
     */
    public function logout(Request $request): JsonResponse
    {
        return $this->attempt('Could not sign out. Please try again.', function () use ($request): JsonResponse {
            $user = $request->user();

            if (! $user) {
                throw new AuthenticationException;
            }

            $token = $user->currentAccessToken();

            if ($token instanceof PersonalAccessToken) {
                $token->delete();
            }

            return response()->json(['message' => 'Logged out']);
        });
    }
}

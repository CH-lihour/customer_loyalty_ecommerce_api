<?php

namespace App\Http\Controllers;

use App\Http\Requests\UserRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    /**
     * List a page of staff users with their roles.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->attempt('Could not load staff users.', function () use ($request): JsonResponse {
            $users = User::with('role.permissions')->orderBy('name')->orderBy('id')->paginate($this->perPage($request));

            return $this->paginatedResponse($users, 'Users retrieved successfully', fn (User $user) => $user->toApiArray());
        });
    }

    /**
     * Create a staff user.
     */
    public function store(UserRequest $request): JsonResponse
    {
        return $this->attempt('Could not create the staff user.', function () use ($request): JsonResponse {
            $user = User::create($request->validated());

            return response()->json([
                'success' => true,
                'message' => 'User created successfully',
                'data' => $user->refresh()->load('role.permissions')->toApiArray(),
            ], 201);
        });
    }

    /**
     * Show a staff user.
     */
    public function show(User $user): JsonResponse
    {
        return $this->attempt('Could not load the staff user.', fn (): JsonResponse => response()->json([
            'success' => true,
            'message' => 'User retrieved successfully',
            'data' => $user->load('role.permissions')->toApiArray(),
        ]));
    }

    /**
     * Update a staff user. Disabling an account signs it out everywhere.
     */
    public function update(UserRequest $request, User $user): JsonResponse
    {
        return $this->attempt('Could not update the staff user.', function () use ($request, $user): JsonResponse {
            $actor = $request->user();

            if (! $actor instanceof User) {
                throw new AuthenticationException;
            }

            $user->fill($request->validated());
            $error = $this->protectedChangeError($actor, $user);

            if ($error) {
                return response()->json(['success' => false, 'message' => $error], 422);
            }

            DB::transaction(function () use ($user): void {
                $user->save();

                if ($user->wasChanged('is_active') && ! $user->is_active) {
                    $user->tokens()->delete();
                }
            });

            return response()->json([
                'success' => true,
                'message' => 'User updated successfully',
                'data' => $user->load('role.permissions')->toApiArray(),
            ]);
        });
    }

    /**
     * Staff cannot lock themselves out, and the last active Super Admin must stay one.
     */
    private function protectedChangeError(User $actor, User $user): ?string
    {
        $superAdminRoleId = Role::where('code', Role::SUPER_ADMIN)->value('id');
        // Without a Super Admin role, a user with no role (null) must not count as holding it.
        $wasActiveSuperAdmin = $superAdminRoleId !== null && $user->getOriginal('is_active') && $user->getOriginal('role_id') === $superAdminRoleId;
        $staysActiveSuperAdmin = $superAdminRoleId !== null && $user->is_active && $user->role_id === $superAdminRoleId;

        if ($user->is($actor) && ! $user->is_active) {
            return 'You cannot disable your own account.';
        }

        if ($user->is($actor) && $wasActiveSuperAdmin && ! $staysActiveSuperAdmin) {
            return 'You cannot remove your own Super Admin role.';
        }

        $otherActiveSuperAdmins = User::whereKeyNot($user->id)
            ->where('role_id', $superAdminRoleId)
            ->where('is_active', true)
            ->exists();

        if ($wasActiveSuperAdmin && ! $staysActiveSuperAdmin && ! $otherActiveSuperAdmins) {
            return 'At least one active Super Admin is required.';
        }

        return null;
    }
}

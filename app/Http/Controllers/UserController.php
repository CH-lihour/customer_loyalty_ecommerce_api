<?php

namespace App\Http\Controllers;

use App\Http\Requests\UserRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * List a page of staff users with their roles.
     */
    public function index(Request $request): JsonResponse
    {
        $users = User::with('role.permissions')->orderBy('name')->orderBy('id')->paginate($this->perPage($request));

        return $this->paginatedResponse($users, 'Users retrieved successfully', fn (User $user) => $user->toApiArray());
    }

    /**
     * Create a staff user.
     */
    public function store(UserRequest $request): JsonResponse
    {
        $user = User::create($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'User created successfully',
            'data' => $user->refresh()->load('role.permissions')->toApiArray(),
        ], 201);
    }

    /**
     * Show a staff user.
     */
    public function show(User $user): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'User retrieved successfully',
            'data' => $user->load('role.permissions')->toApiArray(),
        ]);
    }

    /**
     * Update a staff user. Disabling an account signs it out everywhere.
     */
    public function update(UserRequest $request, User $user): JsonResponse
    {
        $user->fill($request->validated());
        $error = $this->protectedChangeError($request->user(), $user);

        if ($error) {
            return response()->json(['success' => false, 'message' => $error], 422);
        }

        $user->save();

        if ($user->wasChanged('is_active') && ! $user->is_active) {
            $user->tokens()->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'User updated successfully',
            'data' => $user->load('role.permissions')->toApiArray(),
        ]);
    }

    /**
     * Staff cannot lock themselves out, and the last active Super Admin must stay one.
     */
    private function protectedChangeError(User $actor, User $user): ?string
    {
        $superAdminRoleId = Role::where('code', Role::SUPER_ADMIN)->value('id');
        $wasActiveSuperAdmin = $user->getOriginal('is_active') && $user->getOriginal('role_id') === $superAdminRoleId;
        $staysActiveSuperAdmin = $user->is_active && $user->role_id === $superAdminRoleId;

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

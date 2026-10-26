<?php

namespace App\Http\Controllers;

use App\Http\Requests\RoleRequest;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RoleController extends Controller
{
    /**
     * List staff roles with their pages and how many users hold each.
     */
    public function index(): JsonResponse
    {
        return $this->attempt('Could not load roles.', function (): JsonResponse {
            $roles = Role::with('permissions')->withCount('users')->orderBy('id')->get();

            return response()->json([
                'success' => true,
                'message' => 'Roles retrieved successfully',
                'data' => $roles->map->toApiArray(),
            ]);
        });
    }

    /**
     * Create a role. It starts with Dashboard access unless pages are given.
     */
    public function store(RoleRequest $request): JsonResponse
    {
        return $this->attempt('Could not create the role.', function () use ($request): JsonResponse {
            $data = $request->validated();

            $role = DB::transaction(function () use ($data): Role {
                $role = Role::create([
                    'code' => $this->uniqueCode($data['label']),
                    'label' => $data['label'],
                    'stock_authority' => $data['stock_authority'] ?? 'clerk',
                ]);
                $role->syncPages($data['pages'] ?? ['dashboard']);

                return $role;
            });

            return response()->json([
                'success' => true,
                'message' => 'Role created successfully',
                'data' => $role->loadCount('users')->toApiArray(),
            ], 201);
        });
    }

    /**
     * Show a role.
     */
    public function show(Role $role): JsonResponse
    {
        return $this->attempt('Could not load the role.', fn (): JsonResponse => response()->json([
            'success' => true,
            'message' => 'Role retrieved successfully',
            'data' => $role->loadCount('users')->toApiArray(),
        ]));
    }

    /**
     * Rename a role, replace its pages, or change its stock authority. Super Admin is fixed.
     */
    public function update(RoleRequest $request, Role $role): JsonResponse
    {
        return $this->attempt('Could not update the role.', function () use ($request, $role): JsonResponse {
            if ($role->isSuperAdmin()) {
                return $this->superAdminIsFixed();
            }

            $data = $request->validated();

            DB::transaction(function () use ($data, $role): void {
                $role->update(collect($data)->except('pages')->all());

                if (array_key_exists('pages', $data)) {
                    $role->syncPages($data['pages'] ?? []);
                }
            });

            return response()->json([
                'success' => true,
                'message' => 'Role updated successfully',
                'data' => $role->loadCount('users')->toApiArray(),
            ]);
        });
    }

    /**
     * Delete a role that no staff user holds.
     */
    public function destroy(Role $role): JsonResponse
    {
        return $this->attempt('Could not delete the role.', function () use ($role): JsonResponse {
            if ($role->isSuperAdmin()) {
                return $this->superAdminIsFixed();
            }

            $userCount = $role->users()->count();

            if ($userCount > 0) {
                return response()->json([
                    'success' => false,
                    'message' => "Move the {$userCount} staff ".str('user')->plural($userCount)." with the {$role->label} role to another role first.",
                ], 422);
            }

            $role->delete();

            return response()->json([
                'success' => true,
                'message' => 'Role deleted successfully',
            ]);
        });
    }

    /**
     * Build a snake_case code from the label, suffixed when another role already uses it.
     */
    private function uniqueCode(string $label): string
    {
        $base = Str::snake(Str::ascii($label)) ?: 'role';
        $code = $base;

        // Deleted roles keep their code (unique in the database), so they count as taken.
        for ($suffix = 2; Role::withTrashed()->where('code', $code)->exists(); $suffix++) {
            $code = "{$base}_{$suffix}";
        }

        return $code;
    }

    private function superAdminIsFixed(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Super Admin always has full access and cannot be changed.',
        ], 422);
    }
}

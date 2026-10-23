<?php

namespace App\Models;

use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['code', 'label', 'stock_authority'])]
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory, SoftDeletes;

    /**
     * The role that always has every page; its permissions are not stored.
     */
    public const SUPER_ADMIN = 'super_admin';

    /**
     * Every back-office page, matching the frontend's admin page ids.
     *
     * @var list<string>
     */
    public const PAGES = [
        'dashboard', 'analytics', 'customers', 'products', 'categories', 'orders',
        'points', 'tiers', 'campaigns', 'rewards', 'badges', 'referrals',
        'stock-alerts', 'stock', 'stock-movements', 'fulfillment', 'stocktake', 'stock-reports', 'suppliers',
        'logs', 'exchange-rate', 'users', 'roles', 'profile',
    ];

    /**
     * Pages reserved for Super Admin, which other roles can never be given.
     *
     * @var list<string>
     */
    public const RESERVED_PAGES = ['exchange-rate', 'users', 'roles'];

    /**
     * The page every role keeps.
     */
    public const PROFILE_PAGE = 'profile';

    /**
     * Get the stored page permissions.
     *
     * @return HasMany<RolePermission, $this>
     */
    public function permissions(): HasMany
    {
        return $this->hasMany(RolePermission::class);
    }

    /**
     * Get the staff users with this role.
     *
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Whether this is the Super Admin role.
     */
    public function isSuperAdmin(): bool
    {
        return $this->code === self::SUPER_ADMIN;
    }

    /**
     * The pages this role can open, in menu order.
     *
     * @return list<string>
     */
    public function pages(): array
    {
        if ($this->isSuperAdmin()) {
            return self::PAGES;
        }

        $stored = $this->permissions->pluck('page')->all();

        return array_values(array_filter(self::PAGES, fn (string $page) => in_array($page, $stored, true)));
    }

    /**
     * Replace the role's pages. Reserved pages are dropped and the profile page is always kept.
     *
     * @param  list<string>  $pages
     */
    public function syncPages(array $pages): void
    {
        $allowed = array_diff(array_unique([...$pages, self::PROFILE_PAGE]), self::RESERVED_PAGES);

        $this->permissions()->delete();
        $this->permissions()->createMany(array_map(fn (string $page) => ['page' => $page], array_values($allowed)));
        $this->unsetRelation('permissions');
    }

    /**
     * Shape the role for API responses.
     *
     * @return array{id: int, code: string, label: string, stock_authority: string|null, pages: list<string>, users_count?: int}
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'label' => $this->label,
            'stock_authority' => $this->stock_authority,
            'pages' => $this->pages(),
            ...($this->users_count !== null ? ['users_count' => (int) $this->users_count] : []),
        ];
    }
}

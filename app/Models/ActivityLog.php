<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry of the activity log. Entries are only ever added.
 */
#[Fillable(['customer_id', 'user_id', 'event', 'description'])]
class ActivityLog extends Model
{
    /**
     * Get the customer the entry is about.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the staff user who made the change.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Add an entry to the log.
     */
    public static function record(string $event, string $description, ?Customer $customer = null, ?User $staff = null): self
    {
        return self::query()->create([
            'customer_id' => $customer?->id,
            'user_id' => $staff?->id,
            'event' => $event,
            'description' => $description,
        ]);
    }

    /**
     * Shape the entry for API responses. Who acted is the staff user, or the customer when no staff user did.
     *
     * @return array{id: int, customer_id: int|null, user_id: int|null, actor_name: string, event: string, description: string|null, created_at: string|null}
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'user_id' => $this->user_id,
            'actor_name' => $this->user?->name ?? $this->customer?->name ?? 'System',
            'event' => $this->event,
            'description' => $this->description,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

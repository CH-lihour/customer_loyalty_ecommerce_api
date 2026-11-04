<?php

namespace App\Models;

use Database\Factories\ReferralFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['referrer_id', 'referred_id', 'is_completed'])]
class Referral extends Model
{
    /** @use HasFactory<ReferralFactory> */
    use HasFactory;

    /**
     * Get the customer who shared their referral code.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'referrer_id');
    }

    /**
     * Get the customer who signed up with the code.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function referred(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'referred_id');
    }

    /**
     * Shape the referral for API responses.
     *
     * @return array{id: int, referrer_id: int, referrer_name: string|null, referred_id: int, referred_name: string|null, completed: bool, created_at: string|null}
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'referrer_id' => $this->referrer_id,
            'referrer_name' => $this->referrer?->name,
            'referred_id' => $this->referred_id,
            'referred_name' => $this->referred?->name,
            'completed' => $this->is_completed,
            'created_at' => $this->created_at?->toDateString(),
        ];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_completed' => 'boolean',
        ];
    }
}

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

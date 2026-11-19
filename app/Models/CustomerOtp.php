<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;

#[Fillable(['customer_id', 'code_hash', 'attempts', 'expires_at', 'sent_at'])]
#[Hidden(['code_hash'])]
class CustomerOtp extends Model
{
    /**
     * How many digits a code has.
     */
    public const CODE_LENGTH = 6;

    /**
     * How long a code stays valid, in minutes.
     */
    public const EXPIRES_IN_MINUTES = 10;

    /**
     * How many wrong guesses a code allows before a new one must be requested.
     */
    public const MAX_ATTEMPTS = 5;

    /**
     * How long a customer waits before another code can be sent, in seconds.
     */
    public const RESEND_COOLDOWN_SECONDS = 60;

    /**
     * Replace the customer's pending code with a fresh one and return the plain code to email.
     */
    public static function issueFor(Customer $customer): string
    {
        $code = str_pad((string) random_int(0, 10 ** self::CODE_LENGTH - 1), self::CODE_LENGTH, '0', STR_PAD_LEFT);

        self::updateOrCreate(['customer_id' => $customer->id], [
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(self::EXPIRES_IN_MINUTES),
            'sent_at' => now(),
        ]);

        return $code;
    }

    /**
     * Get the customer the code was sent to.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Determine whether the code can no longer be used.
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast() || $this->attempts >= self::MAX_ATTEMPTS;
    }

    /**
     * Determine whether the given code matches the stored hash.
     */
    public function matches(string $code): bool
    {
        return Hash::check($code, $this->code_hash);
    }

    /**
     * Seconds left before another code may be sent; zero when one can be sent now.
     */
    public function secondsUntilResend(): int
    {
        return max(0, (int) ceil(now()->diffInSeconds($this->sent_at->copy()->addSeconds(self::RESEND_COOLDOWN_SECONDS), false)));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }
}

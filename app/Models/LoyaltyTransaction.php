<?php

namespace App\Models;

use Database\Factories\LoyaltyTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of the points ledger. Rows are only ever added: a correction is a new row.
 */
#[Fillable(['customer_id', 'order_id', 'created_by', 'type', 'points', 'balance_before', 'balance_after', 'reason'])]
class LoyaltyTransaction extends Model
{
    /** @use HasFactory<LoyaltyTransactionFactory> */
    use HasFactory;

    public const TYPE_EARN = 'EARN';

    public const TYPE_SPEND = 'SPEND';

    public const TYPE_ADJUST = 'ADJUST';

    public const TYPE_REFERRAL = 'REFERRAL';

    public const TYPE_REFUND = 'REFUND';

    /**
     * The ledger's transaction types, matching the frontend's.
     */
    public const TYPES = [self::TYPE_EARN, self::TYPE_SPEND, self::TYPE_ADJUST, self::TYPE_REFERRAL, self::TYPE_REFUND];

    /**
     * Get the customer whose balance changed.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the staff user who made a manual adjustment.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'balance_before' => 'integer',
            'balance_after' => 'integer',
        ];
    }
}

<?php

namespace App\Models;

use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['customer_id', 'address_id', 'status', 'total_amount', 'ordered_at', 'points_earned', 'delivery_phone'])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    public const PENDING = 'PENDING';

    public const CONFIRMED = 'CONFIRMED';

    public const PROCESSING = 'PROCESSING';

    public const SHIPPED = 'SHIPPED';

    public const COMPLETED = 'COMPLETED';

    public const CANCELLED = 'CANCELLED';

    /**
     * The statuses an order can move to from each status.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::PENDING => [self::CONFIRMED, self::CANCELLED],
        self::CONFIRMED => [self::PROCESSING, self::CANCELLED],
        self::PROCESSING => [self::SHIPPED, self::CANCELLED],
        self::SHIPPED => [self::COMPLETED],
        self::COMPLETED => [],
        self::CANCELLED => [],
    ];

    /**
     * The relations `toApiArray` reads.
     *
     * @var list<string>
     */
    public const RELATIONS = ['customer:id,name', 'address', 'items.product:id,name', 'payment', 'shipment'];

    /**
     * Get the customer who placed the order.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the delivery address.
     *
     * @return BelongsTo<Address, $this>
     */
    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    /**
     * Get the order's lines.
     *
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Get the order's payment.
     *
     * @return HasOne<Payment, $this>
     */
    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    /**
     * Get the order's shipment, once it has one.
     *
     * @return HasOne<Shipment, $this>
     */
    public function shipment(): HasOne
    {
        return $this->hasOne(Shipment::class);
    }

    /**
     * The order's number as staff and customers see it, such as "ORD-000012".
     */
    public function reference(): string
    {
        return 'ORD-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Shape the order for API responses.
     *
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference(),
            'customer_id' => $this->customer_id,
            'customer_name' => $this->customer?->name,
            'status' => $this->status,
            'total' => (float) $this->total_amount,
            'points_earned' => (int) $this->points_earned,
            'ordered_at' => $this->ordered_at?->toIso8601String(),
            'delivery_address' => $this->address?->street,
            'delivery_phone' => $this->delivery_phone,
            'payment' => $this->payment?->only(['method', 'status']),
            'shipment' => $this->shipment?->only(['carrier', 'tracking_no', 'status']),
            'items' => $this->items->map(fn (OrderItem $item): array => [
                'product_id' => $item->product_id,
                'name' => $item->product?->name,
                'quantity' => $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'base_points' => $item->base_points,
                'bonus_multiplier' => (float) $item->bonus_multiplier,
            ])->values()->all(),
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
            'total_amount' => 'decimal:2',
            'ordered_at' => 'datetime',
            'points_earned' => 'integer',
        ];
    }
}

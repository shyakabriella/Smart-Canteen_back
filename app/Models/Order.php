<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use HasFactory, SoftDeletes;

    /*
    |--------------------------------------------------------------------------
    | Order Types
    |--------------------------------------------------------------------------
    */

    public const TYPE_PICKUP = 'pickup';
    public const TYPE_DINE_IN = 'dine_in';
    public const TYPE_TAKEAWAY = 'takeaway';

    /*
    |--------------------------------------------------------------------------
    | Payment Methods
    |--------------------------------------------------------------------------
    */

    public const PAYMENT_METHOD_WALLET = 'wallet';
    public const PAYMENT_METHOD_CASH = 'cash';
    public const PAYMENT_METHOD_MOBILE_MONEY = 'mobile_money';

    /*
    |--------------------------------------------------------------------------
    | Payment Status
    |--------------------------------------------------------------------------
    */

    public const PAYMENT_PENDING = 'pending';
    public const PAYMENT_PAID = 'paid';
    public const PAYMENT_FAILED = 'failed';
    public const PAYMENT_REFUNDED = 'refunded';

    /*
    |--------------------------------------------------------------------------
    | Order Status
    |--------------------------------------------------------------------------
    */

    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_PREPARING = 'preparing';
    public const STATUS_READY = 'ready';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    /*
    |--------------------------------------------------------------------------
    | Pickup Status
    |--------------------------------------------------------------------------
    */

    public const PICKUP_PENDING = 'pending';
    public const PICKUP_READY = 'ready';
    public const PICKUP_COLLECTED = 'collected';
    public const PICKUP_CANCELLED = 'cancelled';

    protected $fillable = [
        'user_id',
        'order_number',
        'order_type',
        'subtotal_amount',
        'discount_amount',
        'tax_amount',
        'total_amount',
        'paid_amount',
        'payment_method',
        'payment_status',
        'order_status',
        'pickup_status',
        'customer_notes',
        'staff_notes',
        'ordered_at',
        'paid_at',
        'confirmed_by',
        'confirmed_at',
        'ready_by',
        'ready_at',
        'completed_by',
        'completed_at',
        'cancelled_by',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'subtotal_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'ordered_at' => 'datetime',
            'paid_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'ready_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    public function qrCode(): HasOne
    {
        return $this->hasOne(OrderQrCode::class, 'order_id');
    }

    public function orderQrCode(): HasOne
    {
        return $this->hasOne(OrderQrCode::class, 'order_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function readyBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ready_by');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class, 'source_id')
            ->whereIn('source_type', [
                WalletTransaction::SOURCE_ORDER_PAYMENT,
                WalletTransaction::SOURCE_REFUND,
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopePaid($query)
    {
        return $query->where('payment_status', self::PAYMENT_PAID);
    }

    public function scopePending($query)
    {
        return $query->where('order_status', self::STATUS_PENDING);
    }

    public function scopeCompleted($query)
    {
        return $query->where('order_status', self::STATUS_COMPLETED);
    }

    public function scopeCancelled($query)
    {
        return $query->where('order_status', self::STATUS_CANCELLED);
    }

    public function scopeReady($query)
    {
        return $query->where('order_status', self::STATUS_READY);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    public function isPaid(): bool
    {
        return $this->payment_status === self::PAYMENT_PAID;
    }

    public function isPending(): bool
    {
        return $this->order_status === self::STATUS_PENDING;
    }

    public function isCompleted(): bool
    {
        return $this->order_status === self::STATUS_COMPLETED;
    }

    public function isCancelled(): bool
    {
        return $this->order_status === self::STATUS_CANCELLED;
    }

    public function isReady(): bool
    {
        return $this->order_status === self::STATUS_READY;
    }

    public function canBeCancelled(): bool
    {
        return !in_array($this->order_status, [
            self::STATUS_COMPLETED,
            self::STATUS_CANCELLED,
        ], true);
    }

    public function canBeCompleted(): bool
    {
        return $this->order_status === self::STATUS_READY;
    }

    public function pickupConfirmation(): HasOne
{
    return $this->hasOne(PickupConfirmation::class, 'order_id');
}

public function confirmation(): HasOne
{
    return $this->hasOne(PickupConfirmation::class, 'order_id');
}
}
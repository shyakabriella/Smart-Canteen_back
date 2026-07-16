<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GuestOrder extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_PREPARING = 'preparing';
    public const STATUS_READY = 'ready';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const PAYMENT_PENDING = 'pending';
    public const PAYMENT_PAID = 'paid';
    public const PAYMENT_CANCELLED = 'cancelled';

    public const DELIVERY_PENDING = 'pending';
    public const DELIVERY_PREPARING = 'preparing';
    public const DELIVERY_OUT_FOR_DELIVERY = 'out_for_delivery';
    public const DELIVERY_DELIVERED = 'delivered';
    public const DELIVERY_CANCELLED = 'cancelled';

    protected $fillable = [
        'order_number',
        'public_token',
        'customer_name',
        'customer_email',
        'customer_phone',
        'delivery_location',
        'preferred_delivery_time',
        'subtotal_amount',
        'discount_amount',
        'tax_amount',
        'total_amount',
        'paid_amount',
        'payment_method',
        'payment_status',
        'order_status',
        'delivery_status',
        'customer_notes',
        'staff_notes',
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

    protected $casts = [
        'subtotal_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'confirmed_at' => 'datetime',
        'ready_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected $hidden = [
        'public_token',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(GuestOrderItem::class);
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'confirmed_by'
        );
    }

    public function readyBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'ready_by'
        );
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'completed_by'
        );
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'cancelled_by'
        );
    }
}

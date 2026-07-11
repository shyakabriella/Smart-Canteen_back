<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseRequest extends Model
{
    use HasFactory, SoftDeletes;

    /*
    |--------------------------------------------------------------------------
    | Status
    |--------------------------------------------------------------------------
    */

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_ORDERED = 'ordered';
    public const STATUS_PARTIALLY_RECEIVED = 'partially_received';
    public const STATUS_RECEIVED = 'received';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'request_number',
        'supplier_id',
        'inventory_stock_id',
        'food_item_id',
        'low_stock_alert_id',
        'quantity_requested',
        'quantity_approved',
        'quantity_received',
        'estimated_unit_cost',
        'estimated_total_cost',
        'received_unit_cost',
        'received_total_cost',
        'status',
        'reason',
        'notes',
        'admin_notes',
        'requested_by',
        'requested_at',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'ordered_by',
        'ordered_at',
        'supplier_reference',
        'received_by',
        'received_at',
        'cancelled_by',
        'cancelled_at',
        'cancellation_reason',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity_requested' => 'integer',
            'quantity_approved' => 'integer',
            'quantity_received' => 'integer',
            'estimated_unit_cost' => 'decimal:2',
            'estimated_total_cost' => 'decimal:2',
            'received_unit_cost' => 'decimal:2',
            'received_total_cost' => 'decimal:2',
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'ordered_at' => 'datetime',
            'received_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function inventoryStock(): BelongsTo
    {
        return $this->belongsTo(InventoryStock::class, 'inventory_stock_id');
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(InventoryStock::class, 'inventory_stock_id');
    }

    public function foodItem(): BelongsTo
    {
        return $this->belongsTo(FoodItem::class, 'food_item_id');
    }

    public function food(): BelongsTo
    {
        return $this->belongsTo(FoodItem::class, 'food_item_id');
    }

    public function lowStockAlert(): BelongsTo
    {
        return $this->belongsTo(LowStockAlert::class, 'low_stock_alert_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function orderedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ordered_by');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopeOrdered($query)
    {
        return $query->where('status', self::STATUS_ORDERED);
    }

    public function scopeReceived($query)
    {
        return $query->where('status', self::STATUS_RECEIVED);
    }

    public function scopeRejected($query)
    {
        return $query->where('status', self::STATUS_REJECTED);
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', self::STATUS_CANCELLED);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isOrdered(): bool
    {
        return $this->status === self::STATUS_ORDERED;
    }

    public function isPartiallyReceived(): bool
    {
        return $this->status === self::STATUS_PARTIALLY_RECEIVED;
    }

    public function isReceived(): bool
    {
        return $this->status === self::STATUS_RECEIVED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function canBeEdited(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function canBeReceived(): bool
    {
        return in_array($this->status, [
            self::STATUS_APPROVED,
            self::STATUS_ORDERED,
            self::STATUS_PARTIALLY_RECEIVED,
        ], true);
    }

    public function remainingQuantity(): int
    {
        return max(0, $this->quantity_approved - $this->quantity_received);
    }
}
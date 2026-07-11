<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LowStockAlert extends Model
{
    use HasFactory, SoftDeletes;

    /*
    |--------------------------------------------------------------------------
    | Alert Types
    |--------------------------------------------------------------------------
    */

    public const TYPE_LOW_STOCK = 'low_stock';
    public const TYPE_OUT_OF_STOCK = 'out_of_stock';
    public const TYPE_RESTOCK_REQUIRED = 'restock_required';

    /*
    |--------------------------------------------------------------------------
    | Severity
    |--------------------------------------------------------------------------
    */

    public const SEVERITY_LOW = 'low';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_HIGH = 'high';
    public const SEVERITY_CRITICAL = 'critical';

    /*
    |--------------------------------------------------------------------------
    | Status
    |--------------------------------------------------------------------------
    */

    public const STATUS_ACTIVE = 'active';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_DISMISSED = 'dismissed';

    protected $fillable = [
        'inventory_stock_id',
        'food_item_id',
        'alert_number',
        'alert_type',
        'severity',
        'current_quantity',
        'threshold_quantity',
        'status',
        'message',
        'notes',
        'resolved_by',
        'resolved_at',
        'resolution_notes',
        'dismissed_by',
        'dismissed_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'current_quantity' => 'integer',
            'threshold_quantity' => 'integer',
            'resolved_at' => 'datetime',
            'dismissed_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

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

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function dismissedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dismissed_by');
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

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeResolved($query)
    {
        return $query->where('status', self::STATUS_RESOLVED);
    }

    public function scopeDismissed($query)
    {
        return $query->where('status', self::STATUS_DISMISSED);
    }

    public function scopeCritical($query)
    {
        return $query->where('severity', self::SEVERITY_CRITICAL);
    }

    public function scopeForFoodItem($query, int $foodItemId)
    {
        return $query->where('food_item_id', $foodItemId);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isResolved(): bool
    {
        return $this->status === self::STATUS_RESOLVED;
    }

    public function isDismissed(): bool
    {
        return $this->status === self::STATUS_DISMISSED;
    }

    public function isCritical(): bool
    {
        return $this->severity === self::SEVERITY_CRITICAL;
    }

    public function purchaseRequests(): HasMany
{
    return $this->hasMany(PurchaseRequest::class, 'low_stock_alert_id');
}
}
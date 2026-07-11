<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryStock extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'food_item_id',
        'quantity',
        'reserved_quantity',
        'low_stock_quantity',
        'location',
        'status',
        'last_restocked_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'reserved_quantity' => 'integer',
            'low_stock_quantity' => 'integer',
            'last_restocked_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function foodItem(): BelongsTo
    {
        return $this->belongsTo(FoodItem::class, 'food_item_id');
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'inventory_stock_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'inventory_stock_id');
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

    public function scopeLowStock($query)
    {
        return $query->whereColumn('quantity', '<=', 'low_stock_quantity');
    }

    public function scopeAvailable($query)
    {
        return $query->where('quantity', '>', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    public function availableQuantity(): int
    {
        return max(0, $this->quantity - $this->reserved_quantity);
    }

    public function isLowStock(): bool
    {
        return $this->quantity <= $this->low_stock_quantity;
    }

    public function isOutOfStock(): bool
    {
        return $this->quantity <= 0;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function lowStockAlerts(): HasMany
{
    return $this->hasMany(LowStockAlert::class, 'inventory_stock_id');
}

public function activeLowStockAlerts(): HasMany
{
    return $this->hasMany(LowStockAlert::class, 'inventory_stock_id')
        ->where('status', LowStockAlert::STATUS_ACTIVE);
}

public function purchaseRequests(): HasMany
{
    return $this->hasMany(PurchaseRequest::class, 'inventory_stock_id');
}

}
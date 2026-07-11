<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class FoodItem extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'food_category_id',
        'name',
        'slug',
        'sku',
        'description',
        'image',
        'price',
        'cost_price',
        'unit',
        'low_stock_quantity',
        'status',
        'is_available',
        'preparation_time_minutes',
        'sort_order',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'low_stock_quantity' => 'integer',
            'is_available' => 'boolean',
            'preparation_time_minutes' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function category(): BelongsTo
    {
        return $this->belongsTo(FoodCategory::class, 'food_category_id');
    }

    public function foodCategory(): BelongsTo
    {
        return $this->belongsTo(FoodCategory::class, 'food_category_id');
    }

    public function inventoryStock(): HasOne
    {
        return $this->hasOne(InventoryStock::class, 'food_item_id');
    }

    public function stock(): HasOne
    {
        return $this->hasOne(InventoryStock::class, 'food_item_id');
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'food_item_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'food_item_id');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'food_item_id');
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

    public function scopeInactive($query)
    {
        return $query->where('status', self::STATUS_INACTIVE);
    }

    public function scopeAvailable($query)
    {
        return $query->where('is_available', true);
    }

    public function scopeUnavailable($query)
    {
        return $query->where('is_available', false);
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

    public function isInactive(): bool
    {
        return $this->status === self::STATUS_INACTIVE;
    }

    public function isAvailable(): bool
    {
        return $this->is_available === true;
    }

    public function isUnavailable(): bool
    {
        return $this->is_available === false;
    }

    public function currentStockQuantity(): int
    {
        return $this->inventoryStock?->quantity ?? 0;
    }

    public function isInStock(): bool
    {
        return $this->currentStockQuantity() > 0;
    }

    public function lowStockAlerts(): HasMany
{
    return $this->hasMany(LowStockAlert::class, 'food_item_id');
}

public function purchaseRequests(): HasMany
{
    return $this->hasMany(PurchaseRequest::class, 'food_item_id');
}
}
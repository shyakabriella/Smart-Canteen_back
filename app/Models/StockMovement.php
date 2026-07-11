<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockMovement extends Model
{
    use HasFactory, SoftDeletes;

    /*
    |--------------------------------------------------------------------------
    | Movement Types
    |--------------------------------------------------------------------------
    */

    public const TYPE_INITIAL_STOCK = 'initial_stock';
    public const TYPE_RESTOCK = 'restock';
    public const TYPE_SALE = 'sale';
    public const TYPE_ADJUSTMENT = 'adjustment';
    public const TYPE_DAMAGED = 'damaged';
    public const TYPE_EXPIRED = 'expired';
    public const TYPE_RETURN = 'return';
    public const TYPE_RESERVED = 'reserved';
    public const TYPE_RELEASE_RESERVED = 'release_reserved';

    protected $fillable = [
        'inventory_stock_id',
        'food_item_id',
        'movement_type',
        'quantity_before',
        'quantity_change',
        'quantity_after',
        'unit_cost',
        'total_cost',
        'reference_type',
        'reference_id',
        'reference_number',
        'reason',
        'notes',
        'movement_date',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity_before' => 'integer',
            'quantity_change' => 'integer',
            'quantity_after' => 'integer',
            'unit_cost' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'movement_date' => 'datetime',
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

    public function foodItem(): BelongsTo
    {
        return $this->belongsTo(FoodItem::class, 'food_item_id');
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

    public function scopeIncrease($query)
    {
        return $query->where('quantity_change', '>', 0);
    }

    public function scopeDecrease($query)
    {
        return $query->where('quantity_change', '<', 0);
    }

    public function scopeType($query, string $type)
    {
        return $query->where('movement_type', $type);
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

    public function isIncrease(): bool
    {
        return $this->quantity_change > 0;
    }

    public function isDecrease(): bool
    {
        return $this->quantity_change < 0;
    }
}
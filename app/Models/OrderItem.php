<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrderItem extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_PREPARED = 'prepared';
    public const STATUS_COLLECTED = 'collected';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'order_id',
        'food_item_id',
        'food_name',
        'food_sku',
        'unit',
        'quantity',
        'unit_price',
        'cost_price',
        'subtotal_amount',
        'discount_amount',
        'tax_amount',
        'total_amount',
        'item_status',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'subtotal_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function foodItem(): BelongsTo
    {
        return $this->belongsTo(FoodItem::class, 'food_item_id');
    }

    public function food(): BelongsTo
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
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    public function isCancelled(): bool
    {
        return $this->item_status === self::STATUS_CANCELLED;
    }

    public function isCollected(): bool
    {
        return $this->item_status === self::STATUS_COLLECTED;
    }
}
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GuestOrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'guest_order_id',
        'food_item_id',
        'food_name',
        'food_sku',
        'unit',
        'quantity',
        'unit_price',
        'subtotal_amount',
        'total_amount',
        'item_status',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'subtotal_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    public function guestOrder(): BelongsTo
    {
        return $this->belongsTo(
            GuestOrder::class
        );
    }

    public function foodItem(): BelongsTo
    {
        return $this->belongsTo(
            FoodItem::class
        );
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryReport extends Model
{
    use HasFactory, SoftDeletes;

    /*
    |--------------------------------------------------------------------------
    | Report Types
    |--------------------------------------------------------------------------
    */

    public const TYPE_CURRENT_STOCK = 'current_stock';
    public const TYPE_DAILY = 'daily';
    public const TYPE_WEEKLY = 'weekly';
    public const TYPE_MONTHLY = 'monthly';
    public const TYPE_CUSTOM = 'custom';

    /*
    |--------------------------------------------------------------------------
    | Status
    |--------------------------------------------------------------------------
    */

    public const STATUS_DRAFT = 'draft';
    public const STATUS_FINAL = 'final';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'report_number',
        'title',
        'report_type',
        'period_start',
        'period_end',

        'total_food_items',
        'active_food_items',
        'inactive_food_items',
        'available_food_items',
        'unavailable_food_items',

        'total_stock_records',
        'total_stock_quantity',
        'total_reserved_quantity',
        'total_available_quantity',

        'low_stock_items',
        'out_of_stock_items',

        'total_stock_cost_value',
        'total_stock_retail_value',

        'total_movements',
        'restock_quantity',
        'sales_quantity',
        'adjustment_quantity',
        'damaged_quantity',
        'expired_quantity',
        'return_quantity',

        'status',
        'report_data',
        'notes',

        'generated_by',
        'generated_at',
        'finalized_by',
        'finalized_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',

            'total_food_items' => 'integer',
            'active_food_items' => 'integer',
            'inactive_food_items' => 'integer',
            'available_food_items' => 'integer',
            'unavailable_food_items' => 'integer',

            'total_stock_records' => 'integer',
            'total_stock_quantity' => 'integer',
            'total_reserved_quantity' => 'integer',
            'total_available_quantity' => 'integer',

            'low_stock_items' => 'integer',
            'out_of_stock_items' => 'integer',

            'total_stock_cost_value' => 'decimal:2',
            'total_stock_retail_value' => 'decimal:2',

            'total_movements' => 'integer',
            'restock_quantity' => 'integer',
            'sales_quantity' => 'integer',
            'adjustment_quantity' => 'integer',
            'damaged_quantity' => 'integer',
            'expired_quantity' => 'integer',
            'return_quantity' => 'integer',

            'report_data' => 'array',
            'generated_at' => 'datetime',
            'finalized_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
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

    public function scopeDraft($query)
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    public function scopeFinal($query)
    {
        return $query->where('status', self::STATUS_FINAL);
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', self::STATUS_CANCELLED);
    }

    public function scopeType($query, string $type)
    {
        return $query->where('report_type', $type);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isFinal(): bool
    {
        return $this->status === self::STATUS_FINAL;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }
}
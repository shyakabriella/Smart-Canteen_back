<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesReport extends Model
{
    use HasFactory, SoftDeletes;

    /*
    |--------------------------------------------------------------------------
    | Report Types
    |--------------------------------------------------------------------------
    */

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

        'total_orders',
        'pending_orders',
        'confirmed_orders',
        'preparing_orders',
        'ready_orders',
        'completed_orders',
        'cancelled_orders',

        'paid_orders',
        'refunded_orders',
        'unpaid_orders',

        'gross_sales',
        'discount_amount',
        'tax_amount',
        'refund_amount',
        'net_sales',

        'wallet_sales',
        'cash_sales',
        'mobile_money_sales',

        'total_items_sold',
        'total_quantity_sold',
        'average_order_value',

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

            'total_orders' => 'integer',
            'pending_orders' => 'integer',
            'confirmed_orders' => 'integer',
            'preparing_orders' => 'integer',
            'ready_orders' => 'integer',
            'completed_orders' => 'integer',
            'cancelled_orders' => 'integer',

            'paid_orders' => 'integer',
            'refunded_orders' => 'integer',
            'unpaid_orders' => 'integer',

            'gross_sales' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'refund_amount' => 'decimal:2',
            'net_sales' => 'decimal:2',

            'wallet_sales' => 'decimal:2',
            'cash_sales' => 'decimal:2',
            'mobile_money_sales' => 'decimal:2',

            'total_items_sold' => 'integer',
            'total_quantity_sold' => 'integer',
            'average_order_value' => 'decimal:2',

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
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class WalletTransaction extends Model
{
    use HasFactory, SoftDeletes;

    /*
    |--------------------------------------------------------------------------
    | Transaction Types
    |--------------------------------------------------------------------------
    */

    public const TYPE_CREDIT = 'credit';
    public const TYPE_DEBIT = 'debit';

    /*
    |--------------------------------------------------------------------------
    | Source Types
    |--------------------------------------------------------------------------
    */

    public const SOURCE_TOP_UP = 'top_up';
    public const SOURCE_ORDER_PAYMENT = 'order_payment';
    public const SOURCE_REFUND = 'refund';
    public const SOURCE_MANUAL_ADJUSTMENT = 'manual_adjustment';

    /*
    |--------------------------------------------------------------------------
    | Status
    |--------------------------------------------------------------------------
    */

    public const STATUS_COMPLETED = 'completed';
    public const STATUS_PENDING = 'pending';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'user_id',
        'wallet_top_up_id',
        'transaction_number',
        'transaction_type',
        'source_type',
        'source_id',
        'reference_number',
        'amount',
        'balance_before',
        'balance_after',
        'status',
        'description',
        'notes',
        'processed_by',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_before' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'processed_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function walletTopUp(): BelongsTo
    {
        return $this->belongsTo(WalletTopUp::class, 'wallet_top_up_id');
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeCredit($query)
    {
        return $query->where('transaction_type', self::TYPE_CREDIT);
    }

    public function scopeDebit($query)
    {
        return $query->where('transaction_type', self::TYPE_DEBIT);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    public function isCredit(): bool
    {
        return $this->transaction_type === self::TYPE_CREDIT;
    }

    public function isDebit(): bool
    {
        return $this->transaction_type === self::TYPE_DEBIT;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }
}
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PickupConfirmation extends Model
{
    use HasFactory, SoftDeletes;

    /*
    |--------------------------------------------------------------------------
    | Confirmation Methods
    |--------------------------------------------------------------------------
    */

    public const METHOD_QR_SCAN = 'qr_scan';
    public const METHOD_MANUAL = 'manual';

    /*
    |--------------------------------------------------------------------------
    | Status
    |--------------------------------------------------------------------------
    */

    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'order_id',
        'order_qr_code_id',
        'qr_scan_log_id',
        'user_id',
        'confirmation_number',
        'confirmation_method',
        'status',
        'customer_name',
        'customer_phone',
        'order_number',
        'qr_code_number',
        'confirmed_by',
        'confirmed_at',
        'cancelled_by',
        'cancelled_at',
        'cancellation_reason',
        'device_name',
        'device_type',
        'ip_address',
        'user_agent',
        'location',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
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

    public function orderQrCode(): BelongsTo
    {
        return $this->belongsTo(OrderQrCode::class, 'order_qr_code_id');
    }

    public function qrCode(): BelongsTo
    {
        return $this->belongsTo(OrderQrCode::class, 'order_qr_code_id');
    }

    public function qrScanLog(): BelongsTo
    {
        return $this->belongsTo(QrScanLog::class, 'qr_scan_log_id');
    }

    public function scanLog(): BelongsTo
    {
        return $this->belongsTo(QrScanLog::class, 'qr_scan_log_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
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

    public function scopeConfirmed($query)
    {
        return $query->where('status', self::STATUS_CONFIRMED);
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', self::STATUS_CANCELLED);
    }

    public function scopeQrScan($query)
    {
        return $query->where('confirmation_method', self::METHOD_QR_SCAN);
    }

    public function scopeManual($query)
    {
        return $query->where('confirmation_method', self::METHOD_MANUAL);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }
}
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class QrScanLog extends Model
{
    use HasFactory, SoftDeletes;

    /*
    |--------------------------------------------------------------------------
    | Scan Actions
    |--------------------------------------------------------------------------
    */

    public const ACTION_VERIFY = 'verify';
    public const ACTION_COLLECT = 'collect';

    /*
    |--------------------------------------------------------------------------
    | Scan Status
    |--------------------------------------------------------------------------
    */

    public const STATUS_VALID = 'valid';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_INVALID = 'invalid';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_USED = 'used';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_UNPAID = 'unpaid';

    protected $fillable = [
        'order_qr_code_id',
        'order_id',
        'user_id',
        'scanned_by',
        'scan_action',
        'scan_status',
        'qr_code_number',
        'qr_token',
        'scanned_payload',
        'message',
        'failure_reason',
        'device_name',
        'device_type',
        'ip_address',
        'user_agent',
        'location',
        'scanned_at',
    ];

    protected function casts(): array
    {
        return [
            'scanned_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function orderQrCode(): BelongsTo
    {
        return $this->belongsTo(OrderQrCode::class, 'order_qr_code_id');
    }

    public function qrCode(): BelongsTo
    {
        return $this->belongsTo(OrderQrCode::class, 'order_qr_code_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scannedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scanned_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeSuccess($query)
    {
        return $query->where('scan_status', self::STATUS_SUCCESS);
    }

    public function scopeFailed($query)
    {
        return $query->whereIn('scan_status', [
            self::STATUS_FAILED,
            self::STATUS_INVALID,
            self::STATUS_EXPIRED,
            self::STATUS_USED,
            self::STATUS_CANCELLED,
            self::STATUS_UNPAID,
        ]);
    }

    public function scopeAction($query, string $action)
    {
        return $query->where('scan_action', $action);
    }

    public function scopeStatus($query, string $status)
    {
        return $query->where('scan_status', $status);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    public function isSuccessful(): bool
    {
        return in_array($this->scan_status, [
            self::STATUS_VALID,
            self::STATUS_SUCCESS,
        ], true);
    }

    public function isFailed(): bool
    {
        return !$this->isSuccessful();
    }

    public function pickupConfirmation()
    {
        return $this->hasOne(PickupConfirmation::class, 'qr_scan_log_id');
    }
}
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrderQrCode extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'order_qr_codes';

    /*
    |--------------------------------------------------------------------------
    | QR Code Status
    |--------------------------------------------------------------------------
    */

    public const STATUS_ACTIVE = 'active';
    public const STATUS_USED = 'used';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'order_id',
        'user_id',
        'qr_code_number',
        'qr_token',
        'qr_payload',
        'qr_image',
        'status',
        'expires_at',
        'used_at',
        'scanned_by',
        'scanned_at',
        'cancelled_by',
        'cancelled_at',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'scanned_at' => 'datetime',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scanLogs(): HasMany
    {
        return $this->hasMany(QrScanLog::class, 'order_qr_code_id');
    }

    public function scannedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scanned_by');
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

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeUsed($query)
    {
        return $query->where('status', self::STATUS_USED);
    }

    public function scopeExpired($query)
    {
        return $query->where('status', self::STATUS_EXPIRED);
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', self::STATUS_CANCELLED);
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

    public function isUsed(): bool
    {
        return $this->status === self::STATUS_USED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isExpired(): bool
    {
        if ($this->status === self::STATUS_EXPIRED) {
            return true;
        }

        return $this->expires_at !== null && now()->greaterThan($this->expires_at);
    }

    public function canBeUsed(): bool
    {
        return $this->isActive() && !$this->isExpired();
    }

    public function pickupConfirmation()
    {
        return $this->hasOne(PickupConfirmation::class, 'order_qr_code_id');
    }
}
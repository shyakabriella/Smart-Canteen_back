<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class ActivityLog extends Model
{
    use HasFactory, SoftDeletes;

    /*
    |--------------------------------------------------------------------------
    | Status
    |--------------------------------------------------------------------------
    */

    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_WARNING = 'warning';

    /*
    |--------------------------------------------------------------------------
    | Severity
    |--------------------------------------------------------------------------
    */

    public const SEVERITY_INFO = 'info';
    public const SEVERITY_LOW = 'low';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_HIGH = 'high';
    public const SEVERITY_CRITICAL = 'critical';

    /*
    |--------------------------------------------------------------------------
    | Common Modules
    |--------------------------------------------------------------------------
    */

    public const MODULE_AUTH = 'auth';
    public const MODULE_USERS = 'users';
    public const MODULE_ORDERS = 'orders';
    public const MODULE_ORDER_ITEMS = 'order_items';
    public const MODULE_QR = 'qr';
    public const MODULE_PICKUP = 'pickup';
    public const MODULE_WALLET = 'wallet';
    public const MODULE_INVENTORY = 'inventory';
    public const MODULE_STOCK = 'stock';
    public const MODULE_SUPPLIERS = 'suppliers';
    public const MODULE_PURCHASE_REQUESTS = 'purchase_requests';
    public const MODULE_REPORTS = 'reports';
    public const MODULE_SYSTEM = 'system';

    protected $fillable = [
        'user_id',
        'log_number',
        'module',
        'action',
        'status',
        'severity',
        'description',
        'subject_type',
        'subject_id',
        'old_values',
        'new_values',
        'metadata',
        'ip_address',
        'user_agent',
        'device_name',
        'device_type',
        'request_method',
        'request_url',
        'occurred_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'metadata' => 'array',
            'occurred_at' => 'datetime',
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

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
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

    public function scopeModule($query, string $module)
    {
        return $query->where('module', $module);
    }

    public function scopeAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    public function scopeSuccess($query)
    {
        return $query->where('status', self::STATUS_SUCCESS);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    public function scopeWarning($query)
    {
        return $query->where('status', self::STATUS_WARNING);
    }

    public function scopeSeverity($query, string $severity)
    {
        return $query->where('severity', $severity);
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

    public function isSuccess(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isWarning(): bool
    {
        return $this->status === self::STATUS_WARNING;
    }

    /*
    |--------------------------------------------------------------------------
    | Static Logger
    |--------------------------------------------------------------------------
    |
    | Example:
    |
    | ActivityLog::record([
    |     'module' => ActivityLog::MODULE_ORDERS,
    |     'action' => 'create',
    |     'description' => 'Order created successfully.',
    |     'subject' => $order,
    | ], $request);
    |
    */

    public static function record(array $data, ?Request $request = null): self
    {
        $subject = $data['subject'] ?? null;

        $userId = $data['user_id']
            ?? $request?->user()?->id
            ?? Auth::id();

        return self::create([
            'user_id' => $userId,
            'log_number' => $data['log_number'] ?? self::generateLogNumber(),

            'module' => $data['module'] ?? self::MODULE_SYSTEM,
            'action' => $data['action'] ?? 'activity',
            'status' => $data['status'] ?? self::STATUS_SUCCESS,
            'severity' => $data['severity'] ?? self::SEVERITY_INFO,
            'description' => $data['description'] ?? null,

            'subject_type' => $subject ? get_class($subject) : ($data['subject_type'] ?? null),
            'subject_id' => $subject?->id ?? ($data['subject_id'] ?? null),

            'old_values' => $data['old_values'] ?? null,
            'new_values' => $data['new_values'] ?? null,
            'metadata' => $data['metadata'] ?? null,

            'ip_address' => $data['ip_address'] ?? $request?->ip(),
            'user_agent' => $data['user_agent'] ?? $request?->userAgent(),
            'device_name' => $data['device_name'] ?? $request?->input('device_name'),
            'device_type' => $data['device_type'] ?? $request?->input('device_type'),
            'request_method' => $data['request_method'] ?? $request?->method(),
            'request_url' => $data['request_url'] ?? $request?->fullUrl(),

            'occurred_at' => $data['occurred_at'] ?? now(),

            'created_by' => $data['created_by'] ?? $userId,
            'updated_by' => $data['updated_by'] ?? $userId,
        ]);
    }

    public static function generateLogNumber(): string
    {
        do {
            $number = 'AL-' . now()->format('Ymd') . '-' . strtoupper(Str::random(10));
        } while (self::where('log_number', $number)->exists());

        return $number;
    }
}
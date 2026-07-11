<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SystemSetting extends Model
{
    use HasFactory, SoftDeletes;

    /*
    |--------------------------------------------------------------------------
    | Value Types
    |--------------------------------------------------------------------------
    */

    public const TYPE_STRING = 'string';
    public const TYPE_INTEGER = 'integer';
    public const TYPE_DECIMAL = 'decimal';
    public const TYPE_BOOLEAN = 'boolean';
    public const TYPE_JSON = 'json';

    /*
    |--------------------------------------------------------------------------
    | Setting Groups
    |--------------------------------------------------------------------------
    */

    public const GROUP_GENERAL = 'general';
    public const GROUP_LOCALIZATION = 'localization';
    public const GROUP_WALLET = 'wallet';
    public const GROUP_QR = 'qr';
    public const GROUP_INVENTORY = 'inventory';
    public const GROUP_ORDERS = 'orders';
    public const GROUP_REPORTS = 'reports';
    public const GROUP_NOTIFICATIONS = 'notifications';
    public const GROUP_SECURITY = 'security';
    public const GROUP_SYSTEM = 'system';

    /*
    |--------------------------------------------------------------------------
    | Status
    |--------------------------------------------------------------------------
    */

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'setting_key',
        'setting_value',
        'value_type',
        'setting_group',
        'label',
        'description',
        'is_public',
        'is_editable',
        'status',
        'sort_order',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
            'is_editable' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

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

    public function scopeInactive($query)
    {
        return $query->where('status', self::STATUS_INACTIVE);
    }

    public function scopePublic($query)
    {
        return $query->where('is_public', true);
    }

    public function scopePrivate($query)
    {
        return $query->where('is_public', false);
    }

    public function scopeGroup($query, string $group)
    {
        return $query->where('setting_group', $group);
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

    public function isInactive(): bool
    {
        return $this->status === self::STATUS_INACTIVE;
    }

    public function isPublic(): bool
    {
        return (bool) $this->is_public;
    }

    public function isEditable(): bool
    {
        return (bool) $this->is_editable;
    }

    public function typedValue(mixed $default = null): mixed
    {
        if ($this->setting_value === null) {
            return $default;
        }

        return match ($this->value_type) {
            self::TYPE_INTEGER => (int) $this->setting_value,
            self::TYPE_DECIMAL => (float) $this->setting_value,
            self::TYPE_BOOLEAN => filter_var(
                $this->setting_value,
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            ) ?? $default,
            self::TYPE_JSON => $this->jsonValue($default),
            default => $this->setting_value,
        };
    }

    private function jsonValue(mixed $default = null): mixed
    {
        $decoded = json_decode($this->setting_value, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $default;
        }

        return $decoded;
    }

    /*
    |--------------------------------------------------------------------------
    | Static Setting Helpers
    |--------------------------------------------------------------------------
    */

    public static function getValue(string $key, mixed $default = null): mixed
    {
        $setting = self::query()
            ->where('setting_key', $key)
            ->where('status', self::STATUS_ACTIVE)
            ->first();

        return $setting ? $setting->typedValue($default) : $default;
    }

    public static function setValue(
        string $key,
        mixed $value,
        string $type = self::TYPE_STRING,
        string $group = self::GROUP_GENERAL,
        ?int $userId = null
    ): self {
        return self::updateOrCreate(
            ['setting_key' => $key],
            [
                'setting_value' => self::normalizeValue($value, $type),
                'value_type' => $type,
                'setting_group' => $group,
                'status' => self::STATUS_ACTIVE,
                'updated_by' => $userId,
                'created_by' => $userId,
            ]
        );
    }

    public static function normalizeValue(mixed $value, string $type = self::TYPE_STRING): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            self::TYPE_INTEGER => (string) ((int) $value),
            self::TYPE_DECIMAL => (string) ((float) $value),
            self::TYPE_BOOLEAN => self::normalizeBoolean($value),
            self::TYPE_JSON => self::normalizeJson($value),
            default => (string) $value,
        };
    }

    private static function normalizeBoolean(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $bool ? '1' : '0';
    }

    private static function normalizeJson(mixed $value): string
    {
        if (is_string($value)) {
            json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                return $value;
            }
        }

        return json_encode($value);
    }
}
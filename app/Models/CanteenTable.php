<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class CanteenTable extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const STATUS_AVAILABLE = 'available';
    public const STATUS_OCCUPIED = 'occupied';
    public const STATUS_RESERVED = 'reserved';
    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'table_number',
        'name',
        'location',
        'capacity',
        'status',
        'description',
        'qr_token',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'capacity' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /*
     * Add qr_url automatically whenever the model
     * is converted to JSON.
     */
    protected $appends = [
        'qr_url',
    ];

    /**
     * Automatically create a unique QR token
     * before saving a new table.
     */
    protected static function booted(): void
    {
        static::creating(function (CanteenTable $canteenTable) {
            if (empty($canteenTable->qr_token)) {
                $canteenTable->qr_token =
                    static::generateUniqueQrToken();
            }
        });
    }

    /**
     * Return all allowed table statuses.
     *
     * @return array<int, string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_AVAILABLE,
            self::STATUS_OCCUPIED,
            self::STATUS_RESERVED,
            self::STATUS_INACTIVE,
        ];
    }

    /**
     * Generate a QR token that does not exist
     * in active or deleted table records.
     */
    public static function generateUniqueQrToken(): string
    {
        do {
            $token = (string) Str::uuid();

            $alreadyExists = static::withTrashed()
                ->where('qr_token', $token)
                ->exists();
        } while ($alreadyExists);

        return $token;
    }

    /**
     * Public URL encoded inside the QR code.
     */
    public function getQrUrlAttribute(): ?string
    {
        if (empty($this->qr_token)) {
            return null;
        }

        $frontendUrl = rtrim(
            (string) config(
                'app.frontend_url',
                'http://localhost:3000'
            ),
            '/'
        );

        return $frontendUrl
            . '/table/'
            . urlencode($this->qr_token);
    }

    /**
     * User who created the table.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by'
        );
    }

    /**
     * User who last updated the table.
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'updated_by'
        );
    }

    /**
     * Filter available tables.
     */
    public function scopeAvailable(
        Builder $query
    ): Builder {
        return $query->where(
            'status',
            self::STATUS_AVAILABLE
        );
    }

    /**
     * Filter active table records.
     */
    public function scopeActive(
        Builder $query
    ): Builder {
        return $query->where(
            'status',
            '!=',
            self::STATUS_INACTIVE
        );
    }
}
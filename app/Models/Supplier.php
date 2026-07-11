<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use HasFactory, SoftDeletes;

    /*
    |--------------------------------------------------------------------------
    | Status
    |--------------------------------------------------------------------------
    */

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'supplier_code',
        'name',
        'contact_person',
        'email',
        'phone',
        'alternate_phone',
        'address',
        'city',
        'country',
        'tin_number',
        'payment_terms',
        'opening_balance',
        'status',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:2',
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

    public function purchaseRequests(): HasMany
{
    return $this->hasMany(PurchaseRequest::class, 'supplier_id');
}
}
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens;

    public const ROLE_ADMIN = 'admin';
    public const ROLE_STAFF = 'staff';
    public const ROLE_STUDENT = 'student';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_BLOCKED = 'blocked';

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'role',
        'status',
        'wallet_balance',
        'user_code',
        'qr_code',
        'device_id',
        'device_name',
        'device_type',
        'device_token',
        'profile_photo',
        'last_login_at',
        'phone_verified_at',
        'email_verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'device_id',
        'device_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'wallet_balance' => 'decimal:2',
            'password' => 'hashed',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isStaff(): bool
    {
        return $this->role === self::ROLE_STAFF;
    }

    public function isStudent(): bool
    {
        return $this->role === self::ROLE_STUDENT;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isBlocked(): bool
    {
        return $this->status === self::STATUS_BLOCKED;
    }

    public function isInactive(): bool
    {
        return $this->status === self::STATUS_INACTIVE;
    }

    public function canManageInventory(): bool
    {
        return in_array($this->role, [
            self::ROLE_ADMIN,
            self::ROLE_STAFF,
        ], true);
    }

    public function canManageWallet(): bool
    {
        return in_array($this->role, [
            self::ROLE_ADMIN,
            self::ROLE_STAFF,
        ], true);
    }

    public function canManageOrders(): bool
    {
        return in_array($this->role, [
            self::ROLE_ADMIN,
            self::ROLE_STAFF,
        ], true);
    }

    /*
    |--------------------------------------------------------------------------
    | Food Category Relationships
    |--------------------------------------------------------------------------
    */

    public function createdFoodCategories(): HasMany
    {
        return $this->hasMany(FoodCategory::class, 'created_by');
    }

    public function updatedFoodCategories(): HasMany
    {
        return $this->hasMany(FoodCategory::class, 'updated_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Food Item Relationships
    |--------------------------------------------------------------------------
    */

    public function createdFoodItems(): HasMany
    {
        return $this->hasMany(FoodItem::class, 'created_by');
    }

    public function updatedFoodItems(): HasMany
    {
        return $this->hasMany(FoodItem::class, 'updated_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Inventory Stock Relationships
    |--------------------------------------------------------------------------
    */

    public function createdInventoryStocks(): HasMany
    {
        return $this->hasMany(InventoryStock::class, 'created_by');
    }

    public function updatedInventoryStocks(): HasMany
    {
        return $this->hasMany(InventoryStock::class, 'updated_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Stock Movement Relationships
    |--------------------------------------------------------------------------
    */

    public function createdStockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'created_by');
    }

    public function updatedStockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'updated_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Wallet Top-up Relationships
    |--------------------------------------------------------------------------
    */

    public function topUpRequests(): HasMany
    {
        return $this->hasMany(WalletTopUp::class, 'user_id');
    }

    public function requestedWalletTopUps(): HasMany
    {
        return $this->hasMany(WalletTopUp::class, 'requested_by');
    }

    public function approvedWalletTopUps(): HasMany
    {
        return $this->hasMany(WalletTopUp::class, 'approved_by');
    }

    public function rejectedWalletTopUps(): HasMany
    {
        return $this->hasMany(WalletTopUp::class, 'rejected_by');
    }

    public function cancelledWalletTopUps(): HasMany
    {
        return $this->hasMany(WalletTopUp::class, 'cancelled_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Wallet Transaction Relationships
    |--------------------------------------------------------------------------
    */

    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class, 'user_id');
    }

    public function processedWalletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class, 'processed_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Order Relationships
    |--------------------------------------------------------------------------
    */

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'user_id');
    }

    public function confirmedOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'confirmed_by');
    }

    public function readyOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'ready_by');
    }

    public function completedOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'completed_by');
    }

    public function cancelledOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'cancelled_by');
    }

    public function scannedOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'completed_by');
    }

    public function createdOrderItems(): HasMany
{
    return $this->hasMany(OrderItem::class, 'created_by');
}

public function updatedOrderItems(): HasMany
{
    return $this->hasMany(OrderItem::class, 'updated_by');
}

public function orderQrCodes()
{
    return $this->hasMany(OrderQrCode::class, 'user_id');
}

public function scannedOrderQrCodes()
{
    return $this->hasMany(OrderQrCode::class, 'scanned_by');
}

public function cancelledOrderQrCodes()
{
    return $this->hasMany(OrderQrCode::class, 'cancelled_by');
}

public function createdOrderQrCodes()
{
    return $this->hasMany(OrderQrCode::class, 'created_by');
}

public function updatedOrderQrCodes()
{
    return $this->hasMany(OrderQrCode::class, 'updated_by');
}

public function qrScanLogs()
{
    return $this->hasMany(QrScanLog::class, 'user_id');
}

public function scannedQrLogs()
{
    return $this->hasMany(QrScanLog::class, 'scanned_by');
}

public function pickupConfirmations()
{
    return $this->hasMany(PickupConfirmation::class, 'user_id');
}

public function confirmedPickups()
{
    return $this->hasMany(PickupConfirmation::class, 'confirmed_by');
}

public function cancelledPickupConfirmations()
{
    return $this->hasMany(PickupConfirmation::class, 'cancelled_by');
}

public function createdPickupConfirmations()
{
    return $this->hasMany(PickupConfirmation::class, 'created_by');
}

public function updatedPickupConfirmations()
{
    return $this->hasMany(PickupConfirmation::class, 'updated_by');
}

public function createdSuppliers(): HasMany
{
    return $this->hasMany(Supplier::class, 'created_by');
}

public function updatedSuppliers(): HasMany
{
    return $this->hasMany(Supplier::class, 'updated_by');
}

public function createdLowStockAlerts(): HasMany
{
    return $this->hasMany(LowStockAlert::class, 'created_by');
}

public function updatedLowStockAlerts(): HasMany
{
    return $this->hasMany(LowStockAlert::class, 'updated_by');
}

public function resolvedLowStockAlerts(): HasMany
{
    return $this->hasMany(LowStockAlert::class, 'resolved_by');
}

public function dismissedLowStockAlerts(): HasMany
{
    return $this->hasMany(LowStockAlert::class, 'dismissed_by');
}

public function requestedPurchaseRequests(): HasMany
{
    return $this->hasMany(PurchaseRequest::class, 'requested_by');
}

public function approvedPurchaseRequests(): HasMany
{
    return $this->hasMany(PurchaseRequest::class, 'approved_by');
}

public function rejectedPurchaseRequests(): HasMany
{
    return $this->hasMany(PurchaseRequest::class, 'rejected_by');
}

public function orderedPurchaseRequests(): HasMany
{
    return $this->hasMany(PurchaseRequest::class, 'ordered_by');
}

public function receivedPurchaseRequests(): HasMany
{
    return $this->hasMany(PurchaseRequest::class, 'received_by');
}

public function cancelledPurchaseRequests(): HasMany
{
    return $this->hasMany(PurchaseRequest::class, 'cancelled_by');
}

public function createdPurchaseRequests(): HasMany
{
    return $this->hasMany(PurchaseRequest::class, 'created_by');
}

public function updatedPurchaseRequests(): HasMany
{
    return $this->hasMany(PurchaseRequest::class, 'updated_by');
}

public function generatedSalesReports(): HasMany
{
    return $this->hasMany(SalesReport::class, 'generated_by');
}

public function finalizedSalesReports(): HasMany
{
    return $this->hasMany(SalesReport::class, 'finalized_by');
}

public function createdSalesReports(): HasMany
{
    return $this->hasMany(SalesReport::class, 'created_by');
}

public function updatedSalesReports(): HasMany
{
    return $this->hasMany(SalesReport::class, 'updated_by');
}

public function generatedInventoryReports(): HasMany
{
    return $this->hasMany(InventoryReport::class, 'generated_by');
}

public function finalizedInventoryReports(): HasMany
{
    return $this->hasMany(InventoryReport::class, 'finalized_by');
}

public function createdInventoryReports(): HasMany
{
    return $this->hasMany(InventoryReport::class, 'created_by');
}

public function updatedInventoryReports(): HasMany
{
    return $this->hasMany(InventoryReport::class, 'updated_by');
}

public function activityLogs(): HasMany
{
    return $this->hasMany(ActivityLog::class, 'user_id');
}

public function createdActivityLogs(): HasMany
{
    return $this->hasMany(ActivityLog::class, 'created_by');
}

public function updatedActivityLogs(): HasMany
{
    return $this->hasMany(ActivityLog::class, 'updated_by');
}

public function createdSystemSettings(): HasMany
{
    return $this->hasMany(SystemSetting::class, 'created_by');
}

public function updatedSystemSettings(): HasMany
{
    return $this->hasMany(SystemSetting::class, 'updated_by');
}

}
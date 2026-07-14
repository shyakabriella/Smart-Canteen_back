<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TableActionEvent extends Model
{
    use HasFactory;

    public const ACTION_ORDER = 'order';
    public const ACTION_CALL = 'call';
    public const ACTION_PAY = 'pay';
    public const ACTION_CANCEL = 'cancel';

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACKNOWLEDGED = 'acknowledged';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'canteen_table_id',
        'action',
        'status',
        'message',
        'source_ip',
        'user_agent',
        'occurred_at',
        'handled_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'handled_at' => 'datetime',
    ];

    /**
     * The canteen table that generated the action.
     */
    public function table(): BelongsTo
    {
        return $this->belongsTo(
            CanteenTable::class,
            'canteen_table_id'
        );
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    /** @use HasFactory<\Database\Factories\SubscriptionFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_FAILED = 'failed';

    public const MODE_INITIAL = 'initial';

    public const MODE_RENEWAL = 'renewal';

    public const MODE_COMP = 'comp';

    protected $fillable = [
        'store_id',
        'subscription_plan_id',
        'provider',
        'provider_customer_id',
        'provider_subscription_id',
        'provider_checkout_session_id',
        'status',
        'mode',
        'period_starts_at',
        'period_ends_at',
        'cancelled_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'period_starts_at' => 'datetime',
            'period_ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'meta' => 'json',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class GiftCard extends Model
{
    public const STATUS_PENDING = 'pending_payment';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PARTIALLY_USED = 'partially_used';
    public const STATUS_FULLY_USED = 'fully_used';
    public const STATUS_VOID = 'void';

    protected $fillable = [
        'code',
        'original_amount',
        'balance',
        'currency',
        'order_id',
        'purchaser_name',
        'purchaser_email',
        'recipient_name',
        'recipient_email',
        'message',
        'status',
        'activated_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'original_amount' => 'decimal:2',
            'balance' => 'decimal:2',
            'activated_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public static function generateCode(): string
    {
        do {
            $code = 'GIFT-' . strtoupper(Str::random(4)) . '-' . strtoupper(Str::random(4)) . '-' . strtoupper(Str::random(4));
        } while (self::where('code', $code)->exists());

        return $code;
    }

    public function isUsable(): bool
    {
        if (! in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_PARTIALLY_USED], true)) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        return (float) $this->balance > 0;
    }

    public function deduct(float $amount): void
    {
        $newBalance = max(0, (float) $this->balance - $amount);
        $this->balance = $newBalance;
        $this->status = $newBalance <= 0 ? self::STATUS_FULLY_USED : self::STATUS_PARTIALLY_USED;
        $this->save();
    }

    public function scopeActive($query): void
    {
        $query->whereIn('status', [self::STATUS_ACTIVE, self::STATUS_PARTIALLY_USED]);
    }
}

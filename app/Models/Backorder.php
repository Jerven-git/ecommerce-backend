<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Backorder extends Model
{
    protected $fillable = [
        'order_id',
        'product_id',
        'quantity',
        'status',
        'charge_policy',
        'stock_reserved',
        'payment_token',
        'token_expires_at',
        'notified_at',
        'paid_at',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'stock_reserved' => 'boolean',
        'token_expires_at' => 'datetime',
        'notified_at' => 'datetime',
        'paid_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function scopeAwaitingStock($query)
    {
        return $query->where('status', 'awaiting_stock');
    }

    public function scopeNotified($query)
    {
        return $query->where('status', 'notified');
    }

    public function scopeExpired($query)
    {
        return $query->where('status', 'expired');
    }

    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', 'cancelled');
    }

    public function scopeConfirmed($query)
    {
        return $query->where('status', 'confirmed');
    }

    public function isTokenValid(): bool
    {
        return $this->payment_token
            && $this->token_expires_at
            && $this->token_expires_at->isFuture();
    }
}

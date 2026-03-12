<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Payment;
use App\Models\Shipment;

class Order extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'customer_name',
        'customer_email',
        'customer_phone',
        'shipping_address',
        'total_amount',
        'subtotal',
        'tax_amount',
        'shipping_amount',
        'discount_code',
        'discount_amount',
        'status',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'shipping_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payment()
    {
        return $this->hasOne(Payment::class)->latestOfMany();
    }

    public function shipment()
    {
        return $this->hasOne(Shipment::class)->latestOfMany();
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeProcessing($query)
    {
        return $query->where('status', 'processing');
    }

    public function scopeShipped($query)
    {
        return $query->where('status', 'shipped');
    }

    public function scopeDelivered($query)
    {
        return $query->where('status', 'delivered');
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', 'cancelled');
    }

    public function scopeSearch($query, ?string $term)
    {
        if (blank($term)) {
            return $query;
        }

        $idCandidate = ltrim(preg_replace('/^[Oo]rder\s*/', '', $term), '# ');

        return $query->where(function ($q) use ($term, $idCandidate) {
            $q->where('customer_name', 'like', "%{$term}%")
            ->orWhere('customer_email', 'like', "%{$term}%");

            if (ctype_digit($idCandidate)) {
                $q->orWhere('id', (int) $idCandidate);
            }
        });
    }
}

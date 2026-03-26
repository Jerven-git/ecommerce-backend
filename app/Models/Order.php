<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;
use App\Models\Payment;
use App\Models\Shipment;
use App\Models\Backorder;

class Order extends Model
{
    use HasFactory;
    const UPDATED_AT = null;

    protected $fillable = [
        'customer_name',
        'customer_email',
        'customer_phone',
        'shipping_address',
        'delivery_method',
        'country',
        'state',
        'city',
        'total_amount',
        'subtotal',
        'tax_amount',
        'shipping_amount',
        'discount_code',
        'discount_amount',
        'tax_rule_id',
        'tax_region',
        'status',
        'has_backorder_items',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'shipping_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'has_backorder_items' => 'boolean',
        'created_at' => 'datetime',
    ];

    protected function customerName(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => strip_tags(trim($value)),
        );
    }

    protected function customerEmail(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => strtolower(trim($value)),
        );
    }

    protected function customerPhone(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value ? trim($value) : $value,
        );
    }

    protected function shippingAddress(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value ? strip_tags(trim($value)) : $value,
        );
    }

    protected function country(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value ? trim($value) : $value,
        );
    }

    protected function state(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value ? trim($value) : $value,
        );
    }

    protected function city(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value ? trim($value) : $value,
        );
    }

    protected function discountCode(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value ? strtoupper(trim($value)) : $value,
        );
    }

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

    public function backorders()
    {
        return $this->hasMany(Backorder::class);
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

    public function scopeBackorder($query)
    {
        return $query->whereIn('status', [
            'backorder_awaiting_stock',
            'backorder_notified',
            'backorder_expired',
            'backorder_cancelled',
        ]);
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

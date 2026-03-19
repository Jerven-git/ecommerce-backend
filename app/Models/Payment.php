<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;

class Payment extends Model
{
    protected $fillable = [
        'order_id','provider','provider_ref','status','amount','currency','meta', 'public_token',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    protected function provider(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => strtolower(trim($value)),
        );
    }

    protected function currency(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => strtoupper(trim($value)),
        );
    }

    protected function providerRef(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value ? trim($value) : $value,
        );
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;

class ShippingSetting extends Model
{
    protected $fillable = [
        'express_post_fee',
        'registered_post_fee',
        'insurance_fee',
        'free_shipping_threshold',
        'store_country',
        'store_state',
        'store_city',
    ];

    protected function storeCountry(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value ? trim($value) : $value,
        );
    }

    protected function storeState(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value ? trim($value) : $value,
        );
    }

    protected function storeCity(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value ? trim($value) : $value,
        );
    }

    protected $casts = [
        'express_post_fee' => 'float',
        'registered_post_fee' => 'float',
        'insurance_fee' => 'float',
        'free_shipping_threshold' => 'float',
    ];
}

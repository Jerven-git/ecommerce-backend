<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    protected $casts = [
        'express_post_fee' => 'float',
        'registered_post_fee' => 'float',
        'insurance_fee' => 'float',
        'free_shipping_threshold' => 'float',
    ];
}

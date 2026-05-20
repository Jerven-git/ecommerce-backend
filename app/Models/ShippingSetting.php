<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class ShippingSetting extends Model
{
    use BelongsToStore;

    protected $fillable = [
        'express_post_fee',
        'registered_post_fee',
        'insurance_fee',
        'insurance_rate_percent',
        'insurance_min_fee',
        'express_label',
        'express_pricing_mode',
        'express_weight_tiers',
        'registered_label',
        'insurance_label',
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
        'insurance_rate_percent' => 'float',
        'insurance_min_fee' => 'float',
        'express_weight_tiers' => 'array',
        'free_shipping_threshold' => 'float',
    ];
}

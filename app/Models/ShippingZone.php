<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShippingZone extends Model
{
    protected $fillable = [
        'zone_type',
        'enabled',
        'base_rate',
        'per_kg_rate',
        'per_cbm_rate',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'base_rate' => 'float',
        'per_kg_rate' => 'float',
        'per_cbm_rate' => 'float',
    ];

    public function calculateShipping($weight = 0, $volumeCbm = 0)
    {
        if (!$this->enabled) {
            return null;
        }

        $total = $this->base_rate;

        if ($weight > 0 && $this->per_kg_rate > 0) {
            $total += ($weight * $this->per_kg_rate);
        }

        if ($volumeCbm > 0 && $this->per_cbm_rate > 0) {
            $total += ($volumeCbm * $this->per_cbm_rate);
        }

        return $total;
    }
}

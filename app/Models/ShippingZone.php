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
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'base_rate' => 'float',
        'per_kg_rate' => 'float', 
    ];

    public function calculateShipping($weight = 0)
    {
        if (!$this->enabled) {
            return null;
        }

        $total = $this->base_rate;
        
        if ($weight > 0 && $this->per_kg_rate > 0) {
            $total += ($weight * $this->per_kg_rate);
        }

        return $total;
    }
}

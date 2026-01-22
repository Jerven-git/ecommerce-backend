<?php

namespace App\Services;

use App\Models\ShippingSetting;
use App\Models\ShippingZone;

class ShippingCalculator
{
    protected $settings;

    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        $this->settings = ShippingSetting::first();
    }
    public function calculateShipping($params)
    {
        $country = $params['country'] ?? '';
        $state = $params['state'] ?? '';
        $city = $params['city'] ?? '';
        $weight = $params['weight'] ?? 0;
        $orderAmount = $params['order_amount'] ?? 0;
        $options = $params['options'] ?? [];

        // Check for free shipping
        if ($this->settings && $this->settings->free_shipping_threshold > 0 && $orderAmount >= $this->settings->free_shipping_threshold) {
            return [
                'base_shipping' => 0,
                'zone' => 'free_shipping',
                'options_fee' => $this->calculateOptionsFee($options),
                'total' => $this->calculateOptionsFee($options)
            ];
        }

        // Determine shipping zone
        $zoneType = $this->determineZone($country, $state, $city);
        $zone = ShippingZone::where('zone_type', $zoneType)->where('enabled', true)->first();

        if (!$zone) {
            return [
                'error' => 'Shipping not available for this location'
            ];
        }

        $baseShipping = $zone->calculateShipping($weight);
        $optionsFee = $this->calculateOptionsFee($options);

        return [
            'base_shipping' => $baseShipping,
            'zone' => $zoneType,
            'options_fee' => $optionsFee,
            'total' => $baseShipping + $optionsFee
        ];
    }

    protected function determineZone($country, $state, $city)
    {
        if (!$this->settings) {
            return 'own_country';
        }

        $storeCountry = strtolower(trim($this->settings->store_country));
        $storeState = strtolower(trim($this->settings->store_state));
        $storeCity = strtolower(trim($this->settings->store_city));

        $customerCountry = strtolower(trim($country));
        $customerState = strtolower(trim($state));
        $customerCity = strtolower(trim($city));

        // Same country
        if ($customerCountry === $storeCountry) {
            // Same city
            if ($customerCity === $storeCity) {
                return 'own_city';
            }
            // Same state, different city
            if ($customerState === $storeState) {
                return 'own_state';
            }
            // Same country, different state
            return 'own_country';
        }

        // Different country - you can add logic for specific cities/states
        return 'other_country';
    }

    protected function calculateOptionsFee($options)
    {
        if (!$this->settings) {
            return 0;
        }

        $fee = 0;

        if (in_array('express_post', $options)) {
            $fee += $this->settings->express_post_fee;
        }

        if (in_array('registered_post', $options)) {
            $fee += $this->settings->registered_post_fee;
        }

        if (in_array('insurance', $options)) {
            $fee += $this->settings->insurance_fee;
        }

        return $fee;
    }
}


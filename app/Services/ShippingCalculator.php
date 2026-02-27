<?php

namespace App\Services;

use App\Models\ShippingSetting;
use App\Models\ShippingZone;

class ShippingCalculator
{
    protected $settings;

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
        $volumeCbm = $params['volume_cbm'] ?? 0;
        $orderAmount = $params['order_amount'] ?? 0;
        $options = $params['options'] ?? [];

        if ($this->settings && $this->settings->free_shipping_threshold > 0 && $orderAmount >= $this->settings->free_shipping_threshold) {
            return [
                'base_shipping' => 0,
                'weight_fee' => 0,
                'volume_fee' => 0,
                'options_fee' => $this->calculateOptionsFee($options),
                'total' => $this->calculateOptionsFee($options),
                'free_shipping' => true,
                'zone' => 'free_shipping'
            ];
        }

        $zoneType = $this->determineZone($country, $state, $city);
        $zone = ShippingZone::where('zone_type', $zoneType)->where('enabled', true)->first();

        if (!$zone) {
            return [
                'error' => 'Shipping not available for this location'
            ];
        }

        $baseShipping = $zone->base_rate;
        $weightFee = $weight > 0 ? ($weight * $zone->per_kg_rate) : 0;
        $volumeFee = $volumeCbm > 0 ? ($volumeCbm * $zone->per_cbm_rate) : 0;
        $optionsFee = $this->calculateOptionsFee($options);

        return [
            'base_shipping' => $baseShipping,
            'weight_fee' => $weightFee,
            'volume_fee' => $volumeFee,
            'options_fee' => $optionsFee,
            'total' => $baseShipping + $weightFee + $volumeFee + $optionsFee,
            'free_shipping' => false,
            'zone' => $zoneType
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

        // Different country
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

    public function getAvailableOptions()
    {
        if (!$this->settings) {
            return [];
        }

        $options = [];

        if ($this->settings->express_post_fee > 0) {
            $options[] = [
                'id' => 'express_post',
                'name' => 'Express Post',
                'description' => 'Fast delivery (1-2 days)',
                'fee' => $this->settings->express_post_fee
            ];
        }

        if ($this->settings->registered_post_fee > 0) {
            $options[] = [
                'id' => 'registered_post',
                'name' => 'Registered Post',
                'description' => 'Tracking number and proof of delivery',
                'fee' => $this->settings->registered_post_fee
            ];
        }

        if ($this->settings->insurance_fee > 0) {
            $options[] = [
                'id' => 'insurance',
                'name' => 'Shipping Insurance',
                'description' => 'Coverage for lost or damaged items',
                'fee' => $this->settings->insurance_fee
            ];
        }

        return $options;
    }

    public function getEnabledZones()
    {
        return ShippingZone::where('enabled', true)->get()->map(function($zone) {
            return [
                'zone_type' => $zone->zone_type,
                'base_rate' => $zone->base_rate,
                'per_kg_rate' => $zone->per_kg_rate,
                'per_cbm_rate' => $zone->per_cbm_rate,
            ];
        });
    }
}


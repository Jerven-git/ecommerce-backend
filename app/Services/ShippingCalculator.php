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
        $method = $params['method'] ?? 'standard';
        $options = $params['options'] ?? [];

        $zoneType = $this->determineZone($country, $state, $city);
        $zone = ShippingZone::where('zone_type', $zoneType)->where('enabled', true)->first();

        if (!$zone) {
            return [
                'error' => 'Shipping not available for this location'
            ];
        }

        $methodFee = $this->calculateMethodFee($method, $weight, $volumeCbm);
        $addOnsFee = $this->calculateAddOnsFee($options, $orderAmount);

        if ($this->settings && $this->settings->free_shipping_threshold > 0 && $orderAmount >= $this->settings->free_shipping_threshold) {
            return [
                'base_shipping' => 0,
                'weight_fee' => 0,
                'volume_fee' => 0,
                'method_fee' => $methodFee,
                'add_ons_fee' => $addOnsFee,
                'total' => $methodFee + $addOnsFee,
                'free_shipping' => true,
                'zone' => $zoneType,
                'method' => $method,
            ];
        }

        $baseShipping = $zone->base_rate;
        $weightFee = $weight > 0 ? ($weight * $zone->per_kg_rate) : 0;
        $volumeFee = $volumeCbm > 0 ? ($volumeCbm * $zone->per_cbm_rate) : 0;

        return [
            'base_shipping' => $baseShipping,
            'weight_fee' => $weightFee,
            'volume_fee' => $volumeFee,
            'method_fee' => $methodFee,
            'add_ons_fee' => $addOnsFee,
            'total' => $baseShipping + $weightFee + $volumeFee + $methodFee + $addOnsFee,
            'free_shipping' => false,
            'zone' => $zoneType,
            'method' => $method,
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

    /**
     * Calculate the fee for the chosen shipping method (standard, express, registered).
     * Standard has no extra method fee — the zone base/weight/volume rates apply.
     */
    protected function calculateMethodFee($method, $weight, $volumeCbm = 0)
    {
        if (!$this->settings) {
            return 0;
        }

        if ($method === 'express') {
            // Use the greater of actual weight or volumetric weight (L×W×H / 5000).
            // volumeCbm * 200 converts cubic metres to the equivalent kg using the
            // industry-standard 5000 cm³/kg divisor used by AusPost, USPS, DHL, etc.
            $billableWeight = max($weight, $volumeCbm * 200);

            if ($this->settings->express_pricing_mode === 'weight_tiered') {
                return $this->lookupWeightTier($billableWeight);
            }
            return $this->settings->express_post_fee;
        }

        if ($method === 'registered') {
            return $this->settings->registered_post_fee;
        }

        return 0; // standard — zone rates are the base
    }

    /**
     * Look up the express weight tier rate for the given weight.
     * Tiers are stored as JSON: [{max_weight_g: 250, rate: 9.10}, ...]
     * Falls back to flat express fee if no tiers configured.
     */
    protected function lookupWeightTier($weightKg)
    {
        $tiers = $this->settings->express_weight_tiers;

        if (empty($tiers) || !is_array($tiers)) {
            return $this->settings->express_post_fee;
        }

        // Sort tiers by max_weight_g ascending
        usort($tiers, fn ($a, $b) => ($a['max_weight_g'] ?? 0) - ($b['max_weight_g'] ?? 0));

        $weightG = $weightKg * 1000;

        foreach ($tiers as $tier) {
            if ($weightG <= ($tier['max_weight_g'] ?? 0)) {
                return (float) ($tier['rate'] ?? 0);
            }
        }

        // Over the highest tier — use the last tier's rate
        return (float) (end($tiers)['rate'] ?? 0);
    }

    /**
     * Calculate add-on fees (currently only insurance).
     */
    protected function calculateAddOnsFee($options, $orderAmount = 0)
    {
        if (!$this->settings) {
            return 0;
        }

        $fee = 0;

        if (in_array('insurance', $options)) {
            if ($this->settings->insurance_rate_percent > 0) {
                $calc = $orderAmount * ($this->settings->insurance_rate_percent / 100);
                $fee += max($calc, $this->settings->insurance_min_fee);
            } else {
                $fee += $this->settings->insurance_fee;
            }
        }

        return $fee;
    }

    /**
     * Return the available shipping methods for the checkout method selector.
     */
    public function getShippingMethods()
    {
        $methods = [
            [
                'id' => 'standard',
                'name' => 'Standard Post',
                'description' => 'Regular delivery via zone-based rates',
                'pricing_mode' => 'zone_based',
                'fee' => null,
            ],
        ];

        if ($this->settings) {
            $expressEnabled = $this->settings->express_post_fee > 0
                || ($this->settings->express_pricing_mode === 'weight_tiered'
                    && !empty($this->settings->express_weight_tiers));

            if ($expressEnabled) {
                $expressMethod = [
                    'id' => 'express',
                    'name' => $this->settings->express_label ?: 'Express Post',
                    'description' => 'Fast delivery (1-2 days)',
                    'pricing_mode' => $this->settings->express_pricing_mode ?? 'flat',
                ];

                if ($this->settings->express_pricing_mode === 'weight_tiered') {
                    $expressMethod['tiers'] = $this->settings->express_weight_tiers ?? [];
                    $expressMethod['fee'] = null;
                } else {
                    $expressMethod['fee'] = $this->settings->express_post_fee;
                }

                $methods[] = $expressMethod;
            }

            if ($this->settings->registered_post_fee > 0) {
                $methods[] = [
                    'id' => 'registered',
                    'name' => $this->settings->registered_label ?: 'Registered Post',
                    'description' => 'Tracking number and proof of delivery',
                    'pricing_mode' => 'flat',
                    'fee' => $this->settings->registered_post_fee,
                ];
            }
        }

        return $methods;
    }

    /**
     * Return the available shipping add-ons (insurance, etc.) for checkout checkboxes.
     */
    public function getShippingAddOns()
    {
        if (!$this->settings) {
            return [];
        }

        $addOns = [];

        $insuranceConfigured = $this->settings->insurance_rate_percent > 0
            || $this->settings->insurance_fee > 0;

        if ($insuranceConfigured) {
            $addOns[] = [
                'id' => 'insurance',
                'name' => $this->settings->insurance_label ?: 'Shipping Insurance',
                'description' => 'Coverage for lost or damaged items',
                'fee' => $this->settings->insurance_fee,
                'value_based' => $this->settings->insurance_rate_percent > 0,
                'rate_percent' => (float) $this->settings->insurance_rate_percent,
                'min_fee' => (float) $this->settings->insurance_min_fee,
            ];
        }

        return $addOns;
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

<?php

namespace App\Modules\CommerceSettings\Application;

use App\Models\TaxRule;
use App\Models\TaxSetting;

class TaxConfiguration
{
    public function settings(): TaxSetting
    {
        return TaxSetting::firstOrCreate([]);
    }

    public function update(array $attributes): TaxSetting
    {
        $settings = $this->settings();
        $settings->update($attributes);

        return $settings;
    }

    public function calculate(float $price): array
    {
        return $this->settings()->calculateTax($price);
    }

    public function calculateCart(array $attributes): array
    {
        $settings = $this->settings();
        $resolved = $settings->resolveForRegion(
            $attributes['country'] ?? null,
            $attributes['state'] ?? null,
        );

        $result = $settings->calculateOrderTotals(
            $attributes['items'],
            (float) ($attributes['discount_amount'] ?? 0),
            (float) ($attributes['shipping_amount'] ?? 0),
            $resolved['rate'],
            $resolved['name'],
            $resolved['mode'],
        );

        $result['rule_id'] = $resolved['rule_id'];
        $result['region_label'] = $resolved['region_label'];
        $result['has_regional_rules'] = TaxRule::where('enabled', true)->exists();

        return $result;
    }

    public function resolve(?string $country, ?string $state): array
    {
        return $this->settings()->resolveForRegion($country, $state);
    }
}

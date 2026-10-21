<?php

namespace App\Modules\CommerceSettings\Application;

use App\Models\ShippingSetting;
use App\Models\ShippingZone;
use App\Services\ShippingCalculator;
use Illuminate\Support\Facades\DB;

class ShippingConfiguration
{
    public function __construct(private readonly ShippingCalculator $calculator) {}

    public function get(): array
    {
        $settings = ShippingSetting::firstOrCreate([]);

        return array_merge($settings->toArray(), ['zones' => ShippingZone::all()]);
    }

    public function update(array $attributes): ShippingSetting
    {
        return DB::transaction(function () use ($attributes): ShippingSetting {
            $zones = $attributes['zones'] ?? null;
            unset($attributes['zones']);

            $settings = ShippingSetting::firstOrCreate([]);
            $settings->update($attributes);

            if ($zones !== null) {
                foreach ($zones as $zone) {
                    ShippingZone::updateOrCreate(
                        ['zone_type' => $zone['zone_type']],
                        [
                            'enabled' => $zone['enabled'],
                            'base_rate' => $zone['base_rate'],
                            'per_kg_rate' => $zone['per_kg_rate'] ?? 0,
                            'per_cbm_rate' => $zone['per_cbm_rate'] ?? 0,
                        ],
                    );
                }
            }

            return $settings;
        });
    }

    public function calculate(array $parameters): array
    {
        return $this->calculator->calculateShipping($parameters);
    }

    public function options(): array
    {
        return [
            'methods' => $this->calculator->getShippingMethods(),
            'add_ons' => $this->calculator->getShippingAddOns(),
        ];
    }

    public function enabledZones(): mixed
    {
        return $this->calculator->getEnabledZones();
    }
}

<?php

namespace App\Modules\Backorders\Application;

use App\Models\SiteConfig;

class BackorderSettings
{
    /** @return array{backorder_enabled: bool, backorder_payment_link_expiry_hours: int} */
    public function read(): array
    {
        $config = SiteConfig::first();

        return [
            'backorder_enabled' => (bool) ($config?->backorder_enabled ?? false),
            'backorder_payment_link_expiry_hours' => (int) ($config?->backorder_payment_link_expiry_hours ?? 24),
        ];
    }

    /** @param array{backorder_enabled: bool, backorder_payment_link_expiry_hours: int} $settings */
    public function update(array $settings): array
    {
        $config = SiteConfig::first() ?? SiteConfig::create([]);
        $config->update($settings);

        return [
            'backorder_enabled' => (bool) $config->backorder_enabled,
            'backorder_payment_link_expiry_hours' => (int) $config->backorder_payment_link_expiry_hours,
        ];
    }

    public function paymentLinkExpiryHours(): int
    {
        return (int) (SiteConfig::first()?->backorder_payment_link_expiry_hours ?? 24);
    }
}

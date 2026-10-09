<?php

namespace App\Support\Tenancy;

use App\Models\Currency;
use App\Models\PaymentSetting;
use App\Models\Scopes\StoreScope;
use App\Models\ShippingSetting;
use App\Models\ShippingZone;
use App\Models\SiteConfig;
use App\Models\Store;
use App\Models\TaxSetting;
use App\Models\User;

class StoreReadiness
{
    /**
     * Build a safe, read-only operational assessment for a tenant.
     *
     * @return array<string, mixed>
     */
    public function assess(Store $store): array
    {
        $siteConfig = SiteConfig::withoutGlobalScope(StoreScope::class)
            ->where('store_id', $store->id)
            ->first();
        $shopEnabled = ($siteConfig?->modules_enabled['shop'] ?? true) !== false;
        $activeAdmins = User::query()
            ->where('store_id', $store->id)
            ->where('status', User::STATUS_ACTIVE)
            ->whereHas('roles', fn ($query) => $query->where('name', 'admin'))
            ->count();
        $currencyCode = $siteConfig ? strtoupper((string) ($siteConfig->currency_code ?: 'USD')) : null;
        $currencyEnabled = $currencyCode !== null
            && Currency::query()->where('code', $currencyCode)->where('is_enabled', true)->exists();
        $paymentProviders = $shopEnabled ? $this->configuredPaymentProviders($store) : [];
        $storefrontUrl = $this->storefrontUrl($store);

        $shipping = ShippingSetting::withoutGlobalScope(StoreScope::class)
            ->where('store_id', $store->id)
            ->first();
        $hasShippingOrigin = $shipping !== null && filled($shipping->store_country);
        $hasShippingZone = ShippingZone::withoutGlobalScope(StoreScope::class)
            ->where('store_id', $store->id)
            ->where('enabled', true)
            ->exists();
        $tax = TaxSetting::withoutGlobalScope(StoreScope::class)
            ->where('store_id', $store->id)
            ->first();

        $checks = [
            $this->check(
                'store_status',
                'Store access',
                $store->isActive(),
                true,
                $store->isActive()
                    ? 'Storefront and merchant login are enabled.'
                    : 'The store is inactive, so storefront and merchant login are blocked.'
            ),
            $this->check(
                'subscription',
                'Subscription access',
                $store->slug === Store::DEFAULT_SLUG || $store->hasActiveSubscription(),
                true,
                $store->slug === Store::DEFAULT_SLUG
                    ? 'The platform default store is always accessible.'
                    : ($store->hasActiveSubscription()
                        ? 'Access is available through the current subscription state.'
                        : 'The store is gated until it subscribes or receives a comp.')
            ),
            $this->check(
                'site_config',
                'Tenant configuration',
                $siteConfig !== null,
                true,
                $siteConfig
                    ? 'The store has an isolated storefront configuration.'
                    : 'Required tenant configuration is missing.'
            ),
            $this->check(
                'active_admin',
                'Active administrator',
                $activeAdmins > 0,
                true,
                $activeAdmins > 0
                    ? $activeAdmins.' active '.($activeAdmins === 1 ? 'administrator is' : 'administrators are').' assigned.'
                    : 'Assign and enable at least one store administrator.'
            ),
            $this->check(
                'storefront_route',
                'Storefront route',
                $storefrontUrl !== null,
                true,
                $storefrontUrl
                    ? 'A storefront URL can be generated. Live network reachability is not probed.'
                    : 'Configure STOREFRONT_BASE_DOMAIN before this store can receive a route.'
            ),
            $shopEnabled
                ? $this->check(
                    'currency',
                    'Shop currency',
                    $currencyEnabled,
                    true,
                    $currencyEnabled
                        ? $currencyCode.' is configured and enabled.'
                        : 'Choose an enabled currency in Store Admin settings.'
                )
                : $this->notApplicable('currency', 'Shop currency', 'The Shop module is disabled.'),
            $shopEnabled
                ? $this->check(
                    'payments',
                    'Payment gateway',
                    $paymentProviders !== [],
                    true,
                    $paymentProviders !== []
                        ? 'Ready providers: '.implode(', ', $paymentProviders).'.'
                        : 'Enable a gateway and complete all required credentials in Store Admin.'
                )
                : $this->notApplicable('payments', 'Payment gateway', 'The Shop module is disabled.'),
            $store->domain === null
                ? $this->notApplicable('custom_domain', 'Custom domain', 'Using the platform storefront address; a custom domain is optional.')
                : $this->check(
                    'custom_domain',
                    'Custom domain',
                    $store->hasVerifiedDomain(),
                    false,
                    $store->hasVerifiedDomain()
                        ? 'The custom domain is verified.'
                        : 'The domain is saved but still needs DNS verification.'
                ),
            ($hasShippingOrigin || $hasShippingZone)
                ? $this->check(
                    'shipping',
                    'Shipping setup',
                    $hasShippingOrigin && $hasShippingZone,
                    false,
                    $hasShippingOrigin && $hasShippingZone
                        ? 'A shipping origin and at least one enabled zone are configured.'
                        : 'Shipping setup is partial; pickup can still remain available.'
                )
                : $this->notApplicable('shipping', 'Shipping setup', 'No shipping setup saved; pickup-only operation is still possible.'),
            $tax?->tax_enabled
                ? $this->check('tax', 'Tax settings', true, false, 'Tax calculation is enabled.')
                : $this->notApplicable('tax', 'Tax settings', 'Tax calculation is disabled; this may be intentional.'),
        ];

        $required = array_values(array_filter($checks, fn (array $check) => $check['required']));
        $requiredReady = count(array_filter($required, fn (array $check) => $check['state'] === 'ready'));

        return [
            'status' => $requiredReady === count($required) ? 'ready' : 'needs_attention',
            'summary' => [
                'required_ready' => $requiredReady,
                'required_total' => count($required),
                'attention' => count(array_filter($checks, fn (array $check) => $check['state'] === 'attention')),
            ],
            'shop_enabled' => $shopEnabled,
            'storefront_url' => $storefrontUrl,
            'checks' => $checks,
        ];
    }

    /** @return array{id: string, label: string, state: string, required: bool, detail: string} */
    private function check(string $id, string $label, bool $ready, bool $required, string $detail): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'state' => $ready ? 'ready' : 'attention',
            'required' => $required,
            'detail' => $detail,
        ];
    }

    /** @return array{id: string, label: string, state: string, required: bool, detail: string} */
    private function notApplicable(string $id, string $label, string $detail): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'state' => 'not_applicable',
            'required' => false,
            'detail' => $detail,
        ];
    }

    /** @return list<string> */
    private function configuredPaymentProviders(Store $store): array
    {
        $settings = PaymentSetting::withoutGlobalScope(StoreScope::class)
            ->where('store_id', $store->id)
            ->first();

        if (! $settings) {
            return [];
        }

        $providers = [];

        if ($settings->stripe_enabled
            && filled($settings->stripe_publishable_key)
            && $this->rawCredentialIsSet($settings, 'stripe_secret_key')
            && $this->rawCredentialIsSet($settings, 'stripe_webhook_secret')) {
            $providers[] = 'Stripe';
        }

        if ($settings->paypal_enabled
            && filled($settings->paypal_client_id)
            && filled($settings->paypal_webhook_id)
            && $this->rawCredentialIsSet($settings, 'paypal_secret')) {
            $providers[] = 'PayPal';
        }

        if ($settings->square_enabled
            && filled($settings->square_application_id)
            && filled($settings->square_location_id)
            && $this->rawCredentialIsSet($settings, 'square_access_token')
            && $this->rawCredentialIsSet($settings, 'square_webhook_secret')) {
            $providers[] = 'Square';
        }

        return $providers;
    }

    private function rawCredentialIsSet(PaymentSetting $settings, string $key): bool
    {
        return filled($settings->getRawOriginal($key));
    }

    private function storefrontUrl(Store $store): ?string
    {
        if ($store->hasVerifiedDomain()) {
            return 'https://'.$store->domain;
        }

        $baseDomain = strtolower(trim((string) config('storefront.base_domain')));

        if ($baseDomain === '') {
            return null;
        }

        $host = $store->slug === Store::DEFAULT_SLUG
            ? $baseDomain
            : $store->slug.'.'.$baseDomain;
        $scheme = $baseDomain === 'localhost' || str_ends_with($baseDomain, '.localhost') ? 'http' : 'https';

        return $scheme.'://'.$host;
    }
}

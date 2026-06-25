<?php

namespace App\Support\Tenancy;

use App\Models\Store;

class CurrentStore
{
    protected ?Store $store = null;

    protected bool $resolvedFromHost = false;

    public function set(Store $store, bool $resolvedFromHost = true): void
    {
        $this->store = $store;
        $this->resolvedFromHost = $resolvedFromHost;
    }

    public function clear(): void
    {
        $this->store = null;
        $this->resolvedFromHost = false;
    }

    /**
     * Whether the current store was resolved from the request Host (a custom
     * domain or store subdomain) rather than the bare-apex default fallback.
     * The storefront SPA uses this to send apex visitors to the admin login
     * instead of rendering the default store.
     */
    public function resolvedFromHost(): bool
    {
        return $this->resolvedFromHost;
    }

    public function isSet(): bool
    {
        return $this->store !== null;
    }

    public function get(): ?Store
    {
        return $this->store;
    }

    public function id(): ?int
    {
        return $this->store?->id;
    }
}

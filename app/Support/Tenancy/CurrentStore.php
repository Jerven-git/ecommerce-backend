<?php

namespace App\Support\Tenancy;

use App\Models\Store;

class CurrentStore
{
    protected ?Store $store = null;

    public function set(Store $store): void
    {
        $this->store = $store;
    }

    public function clear(): void
    {
        $this->store = null;
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

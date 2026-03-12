<?php

namespace App\Payments\DTOs;

class PaymentInitData
{
    public function __construct(
        public readonly ?string $providerRef = null,
        public readonly array $meta = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            providerRef: $data['provider_ref'] ?? null,
            meta: $data,
        );
    }

    public function toArray(): array
    {
        return $this->meta;
    }
}

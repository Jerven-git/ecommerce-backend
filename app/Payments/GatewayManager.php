<?php

namespace App\Payments;

use App\Payments\Contracts\PaymentGateway;
use RuntimeException;

class GatewayManager
{
    /** @param array<string,PaymentGateway> $gateways */
    public function __construct(private array $gateways) {}

    public function get(string $provider): PaymentGateway
    {
        if (!isset($this->gateways[$provider])) {
            throw new RuntimeException("Unsupported provider: {$provider}");
        }
        return $this->gateways[$provider];
    }
}
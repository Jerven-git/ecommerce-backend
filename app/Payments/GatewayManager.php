<?php

namespace App\Payments;

use App\Payments\Contracts\PaymentGateway;
use App\Payments\Contracts\HandlesWebhooks;

class UnsupportedGatewayException extends \InvalidArgumentException {}

class GatewayManager
{
    /** @var array<string,PaymentGateway> */
    private array $gateways;

    /** @param array<string,PaymentGateway> $gateways */
    public function __construct(array $gateways)
    {
        // Normalize keys once at construction
        $normalized = [];
        foreach ($gateways as $key => $gateway) {
            $normalized[strtolower(trim($key))] = $gateway;
        }
        $this->gateways = $normalized;
    }

    public function get(string $provider): PaymentGateway
    {
        $key = strtolower(trim($provider));

        if (!isset($this->gateways[$key])) {
            $supported = implode(', ', array_keys($this->gateways));
            throw new UnsupportedGatewayException("Unsupported provider: {$provider}. Supported: {$supported}");
        }

        return $this->gateways[$key];
    }

    /** @return string[] */
    public function supported(): array
    {
        return array_keys($this->gateways);
    }

    public function webhook(string $provider): HandlesWebhooks
    {
        $gateway = $this->get($provider);

        if (!$gateway instanceof HandlesWebhooks) {
            $supported = implode(', ', $this->supported());
            throw new UnsupportedGatewayException("Provider does not support webhooks: {$provider}. Supported: {$supported}");
        }

        return $gateway;
    }
}

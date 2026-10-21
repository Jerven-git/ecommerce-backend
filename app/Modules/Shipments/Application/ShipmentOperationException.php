<?php

namespace App\Modules\Shipments\Application;

use App\Models\Shipment;
use RuntimeException;

class ShipmentOperationException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly ?Shipment $shipment = null,
    ) {
        parent::__construct($message);
    }
}

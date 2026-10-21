<?php

namespace App\Modules\Shipments\Application;

use App\Models\Shipment;
use Picqer\Barcode\BarcodeGeneratorSVG;

class ShipmentTracking
{
    public function find(string $trackingNumber, bool $withOrder = false): Shipment
    {
        $query = Shipment::query()
            ->where('tracking_number', $trackingNumber)
            ->whereHas('order');

        if ($withOrder) {
            $query->with('order:id,customer_name,status,created_at');
        }

        return $query->firstOrFail();
    }

    public function barcode(string $trackingNumber): string
    {
        $shipment = $this->find($trackingNumber);
        $generator = new BarcodeGeneratorSVG;

        return $generator->getBarcode(
            $shipment->tracking_number,
            $generator::TYPE_CODE_128,
        );
    }
}

<?php

namespace App\Modules\Shipments\Application;

use App\Models\Order;
use App\Models\Shipment;

class ShipmentLifecycle
{
    public function orderForShipment(int|string $orderId): Order
    {
        $order = Order::with('shipment')->findOrFail($orderId);
        if ($order->shipment) {
            throw new ShipmentOperationException(
                'Order already has a shipment',
                422,
                $order->shipment,
            );
        }

        return $order;
    }

    public function create(Order $order, ?string $carrier): Shipment
    {
        $shipment = Shipment::create([
            'order_id' => $order->id,
            'tracking_number' => Shipment::generateTrackingNumber(),
            'carrier' => $carrier,
            'status' => 'label_created',
            'shipped_at' => now(),
        ]);

        $order->update(['status' => 'shipped']);

        return $shipment;
    }

    public function shipmentForUpdate(int|string $shipmentId): Shipment
    {
        return Shipment::query()->whereHas('order')->findOrFail($shipmentId);
    }

    public function update(Shipment $shipment, string $status, ?string $carrier): Shipment
    {
        $shipment->update([
            'status' => $status,
            'carrier' => $carrier ?? $shipment->carrier,
            'delivered_at' => $status === 'delivered' ? now() : $shipment->delivered_at,
        ]);

        if ($status === 'delivered') {
            $shipment->order->update(['status' => 'delivered']);
        }

        return $shipment->fresh();
    }
}

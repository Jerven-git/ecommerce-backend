<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Shipments\Application\ShipmentLifecycle;
use App\Modules\Shipments\Application\ShipmentOperationException;
use App\Modules\Shipments\Application\ShipmentTracking;
use Illuminate\Http\Request;

class ShipmentController extends Controller
{
    public function ship(Request $request, int|string $orderId, ShipmentLifecycle $shipments)
    {
        try {
            $order = $shipments->orderForShipment($orderId);
            $validated = $request->validate(['carrier' => 'nullable|string|max:255']);

            return response()->json([
                'message' => 'Shipment created successfully',
                'data' => $shipments->create($order, $validated['carrier'] ?? null),
            ], 201);
        } catch (ShipmentOperationException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'data' => $exception->shipment,
            ], $exception->status);
        }
    }

    public function track(string $trackingNumber, ShipmentTracking $tracking)
    {
        return response()->json([
            'data' => $tracking->find($trackingNumber, withOrder: true),
        ]);
    }

    public function update(Request $request, int|string $id, ShipmentLifecycle $shipments)
    {
        $shipment = $shipments->shipmentForUpdate($id);
        $validated = $request->validate([
            'status' => 'required|in:label_created,in_transit,delivered,returned',
            'carrier' => 'nullable|string|max:255',
        ]);

        return response()->json([
            'message' => 'Shipment updated',
            'data' => $shipments->update(
                $shipment,
                $validated['status'],
                $validated['carrier'] ?? null,
            ),
        ]);
    }

    public function barcode(string $trackingNumber, ShipmentTracking $tracking)
    {
        return response($tracking->barcode($trackingNumber), 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}

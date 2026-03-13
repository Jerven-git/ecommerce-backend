<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Http\Request;
use Picqer\Barcode\BarcodeGeneratorSVG;

class ShipmentController extends Controller
{
    public function ship(Request $request, $orderId)
    {
        $order = Order::with('shipment')->findOrFail($orderId);

        if ($order->shipment) {
            return response()->json([
                'message' => 'Order already has a shipment',
                'data' => $order->shipment,
            ], 422);
        }

        $validated = $request->validate([
            'carrier' => 'nullable|string|max:255',
        ]);

        $shipment = Shipment::create([
            'order_id' => $order->id,
            'tracking_number' => Shipment::generateTrackingNumber(),
            'carrier' => $validated['carrier'] ?? null,
            'status' => 'label_created',
            'shipped_at' => now(),
        ]);

        $order->update(['status' => 'shipped']);

        return response()->json([
            'message' => 'Shipment created successfully',
            'data' => $shipment,
        ], 201);
    }

    public function track($trackingNumber)
    {
        $shipment = Shipment::where('tracking_number', $trackingNumber)
            ->with('order:id,customer_name,status,created_at')
            ->firstOrFail();

        return response()->json(['data' => $shipment]);
    }

    public function update(Request $request, $id)
    {
        $shipment = Shipment::findOrFail($id);

        $validated = $request->validate([
            'status' => 'required|in:label_created,in_transit,delivered,returned',
            'carrier' => 'nullable|string|max:255',
        ]);

        $shipment->update([
            'status' => $validated['status'],
            'carrier' => $validated['carrier'] ?? $shipment->carrier,
            'delivered_at' => $validated['status'] === 'delivered' ? now() : $shipment->delivered_at,
        ]);

        if ($validated['status'] === 'delivered') {
            $shipment->order->update(['status' => 'delivered']);
        }

        return response()->json([
            'message' => 'Shipment updated',
            'data' => $shipment->fresh(),
        ]);
    }

    public function barcode($trackingNumber)
    {
        $shipment = Shipment::where('tracking_number', $trackingNumber)->firstOrFail();

        $generator = new BarcodeGeneratorSVG();
        $svg = $generator->getBarcode($shipment->tracking_number, $generator::TYPE_CODE_128);

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}

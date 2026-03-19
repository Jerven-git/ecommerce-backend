<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShipmentApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $role = Role::create(['name' => 'admin']);
        $this->admin->roles()->attach($role);
    }

    public function test_admin_can_create_shipment(): void
    {
        $order = Order::factory()->create(['status' => 'processing']);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/orders/{$order->id}/ship", [
                'carrier' => 'FedEx',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.carrier', 'FedEx')
            ->assertJsonStructure(['data' => ['tracking_number']]);

        $this->assertEquals('shipped', $order->fresh()->status);
    }

    public function test_cannot_create_duplicate_shipment(): void
    {
        $order = Order::factory()->create(['status' => 'processing']);
        Shipment::create([
            'order_id' => $order->id,
            'tracking_number' => 'SSU-20260319-ABC123',
            'status' => 'label_created',
            'shipped_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/orders/{$order->id}/ship")
            ->assertStatus(422);
    }

    public function test_admin_can_update_shipment_status(): void
    {
        $order = Order::factory()->create(['status' => 'shipped']);
        $shipment = Shipment::create([
            'order_id' => $order->id,
            'tracking_number' => 'SSU-20260319-XYZ789',
            'status' => 'label_created',
            'shipped_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/shipments/{$shipment->id}", [
                'status' => 'delivered',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'delivered');

        $this->assertEquals('delivered', $order->fresh()->status);
    }

    public function test_public_tracking_endpoint(): void
    {
        $order = Order::factory()->create(['status' => 'shipped']);
        Shipment::create([
            'order_id' => $order->id,
            'tracking_number' => 'SSU-20260319-TRACK1',
            'status' => 'in_transit',
            'shipped_at' => now(),
        ]);

        $this->getJson('/api/v1/tracking/SSU-20260319-TRACK1')
            ->assertOk()
            ->assertJsonPath('data.status', 'in_transit');
    }

    public function test_tracking_returns_404_for_invalid_number(): void
    {
        $this->getJson('/api/v1/tracking/INVALID-NUMBER')
            ->assertStatus(404);
    }

    public function test_carrier_is_sanitized(): void
    {
        $order = Order::factory()->create(['status' => 'processing']);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/orders/{$order->id}/ship", [
                'carrier' => '<script>alert(1)</script>FedEx',
            ])
            ->assertStatus(201);

        $shipment = Shipment::where('order_id', $order->id)->first();
        $this->assertStringNotContainsString('<script>', $shipment->carrier);
        $this->assertStringContainsString('FedEx', $shipment->carrier);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Backorder;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\User;
use App\Payments\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CriticalBackendContainmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_status_requires_an_opaque_access_token(): void
    {
        $order = Order::factory()->create();
        $payment = app(PaymentService::class)->createPending($order, 'stripe', [
            'provider_ref' => 'pi_containment',
        ]);
        $payment->update(['status' => 'paid']);

        $this->assertNotNull($payment->public_token);
        $this->assertSame(64, strlen($payment->public_token));

        $this->getJson("/api/v1/payments/{$payment->id}")
            ->assertNotFound();

        $this->withSession([
            'payment_access' => [(string) $payment->id => $payment->public_token],
        ])->withHeaders([
            'Origin' => 'http://localhost',
            'Referer' => 'http://localhost',
        ])->getJson("/api/v1/payments/{$payment->id}")
            ->assertOk()
            ->assertJsonPath('id', $payment->id);

        $this->getJson("/api/v1/payments/{$payment->id}?payment_token={$payment->public_token}")
            ->assertOk()
            ->assertJsonPath('id', $payment->id)
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('order_status', 'pending');
    }

    public function test_idempotent_paypal_capture_returns_payment_access_and_order_outcome(): void
    {
        $order = Order::factory()->create(['status' => 'failed_needs_refund']);
        $payment = app(PaymentService::class)->createPending($order, 'paypal', [
            'provider_ref' => 'paypal_containment',
        ]);
        $payment->update([
            'status' => 'paid',
            'meta' => ['capture_id' => 'capture_containment'],
        ]);

        $this->postJson('/api/v1/paypal/capture', [
            'token' => 'paypal_containment',
        ])->assertOk()
            ->assertJsonPath('payment_id', $payment->id)
            ->assertJsonPath('payment_token', $payment->public_token)
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('order_status', 'failed_needs_refund')
            ->assertJsonPath('capture_id', 'capture_containment');
    }

    public function test_storefront_tenant_resolves_before_order_route_binding(): void
    {
        $defaultOrder = Order::factory()->create();
        $otherStore = Store::factory()->create(['slug' => 'binding-order']);

        $this->withServerVariables([
            'HTTP_HOST' => $otherStore->slug.'.localhost',
            'SERVER_NAME' => $otherStore->slug.'.localhost',
        ])
            ->withHeader('X-Forwarded-Host', $otherStore->slug.'.localhost')
            ->postJson("/api/v1/orders/{$defaultOrder->id}/pay", [
                'provider' => 'paypal',
            ])
            ->assertNotFound();
    }

    public function test_authenticated_non_admin_cannot_access_order_management(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/v1/orders')
            ->assertForbidden();
    }

    public function test_admin_cannot_read_or_mutate_another_stores_backorder(): void
    {
        [$admin, $ownStore, $foreignStore] = $this->tenantAdminAndStores();
        $foreignProduct = Product::factory()->create(['store_id' => $foreignStore->id]);
        $foreignOrder = Order::factory()->create(['store_id' => $foreignStore->id]);
        $foreignBackorder = Backorder::create([
            'order_id' => $foreignOrder->id,
            'product_id' => $foreignProduct->id,
            'quantity' => 1,
            'status' => 'awaiting_stock',
            'charge_policy' => 'charged_later',
        ]);

        $this->actingAs($admin)
            ->getJson('/api/v1/backorders')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($admin)
            ->getJson("/api/v1/backorders/{$foreignBackorder->id}")
            ->assertNotFound();

        $this->actingAs($admin)
            ->postJson("/api/v1/backorders/{$foreignBackorder->id}/cancel")
            ->assertNotFound();

        $this->assertSame('awaiting_stock', $foreignBackorder->fresh()->status);
        $this->assertSame($ownStore->id, $admin->store_id);
    }

    public function test_admin_and_public_tracking_cannot_cross_store_shipment_boundary(): void
    {
        [$admin, $ownStore, $foreignStore] = $this->tenantAdminAndStores();
        $foreignOrder = Order::factory()->create([
            'store_id' => $foreignStore->id,
            'status' => 'shipped',
        ]);
        $foreignShipment = Shipment::create([
            'order_id' => $foreignOrder->id,
            'tracking_number' => 'SSU-FOREIGN-TRACK',
            'status' => 'in_transit',
            'shipped_at' => now(),
        ]);

        $this->actingAs($admin)
            ->patchJson("/api/v1/shipments/{$foreignShipment->id}", [
                'status' => 'delivered',
            ])
            ->assertNotFound();

        $this->withServerVariables([
            'HTTP_HOST' => $ownStore->slug.'.localhost',
            'SERVER_NAME' => $ownStore->slug.'.localhost',
        ])
            ->withHeader('X-Forwarded-Host', $ownStore->slug.'.localhost')
            ->getJson('/api/v1/tracking/SSU-FOREIGN-TRACK')
            ->assertNotFound();

        $this->assertSame('in_transit', $foreignShipment->fresh()->status);
    }

    /** @return array{User, Store, Store} */
    private function tenantAdminAndStores(): array
    {
        $ownStore = Store::factory()->create(['slug' => 'own-store']);
        $foreignStore = Store::factory()->create(['slug' => 'foreign-store']);
        $admin = User::factory()->create(['store_id' => $ownStore->id]);
        $role = Role::create(['name' => 'admin']);
        $admin->roles()->attach($role);

        return [$admin, $ownStore, $foreignStore];
    }
}

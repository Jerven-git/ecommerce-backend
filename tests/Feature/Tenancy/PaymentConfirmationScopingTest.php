<?php

namespace Tests\Feature\Tenancy;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Store;
use App\Payments\PaymentService;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentConfirmationScopingTest extends TestCase
{
    use RefreshDatabase;

    protected Store $defaultStore;

    protected Store $otherStore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->defaultStore = Store::firstOrCreate(
            ['slug' => Store::DEFAULT_SLUG],
            ['name' => 'Default Store', 'status' => 'active']
        );

        $this->otherStore = Store::factory()->create(['name' => 'Watch World']);
    }

    public function test_payment_confirmation_resolves_the_orders_store_not_the_ambient_one(): void
    {
        // An order belonging to the non-default store.
        app(CurrentStore::class)->set($this->otherStore);
        $order = Order::factory()->create(['status' => 'pending']);
        $payment = Payment::create([
            'order_id' => $order->id,
            'provider' => 'paypal',
            'provider_ref' => 'PP-CROSS-STORE',
            'status' => 'pending',
            'amount' => 5000,
            'currency' => 'USD',
        ]);

        // Simulate the webhook / PayPal-return context: the ambient tenant is the
        // WRONG store (or, for webhooks, none at all). Pin it to the default store
        // to prove markPaid overrides it with the order's actual store.
        app(CurrentStore::class)->set($this->defaultStore);

        app(PaymentService::class)->markPaid($payment);

        // UpdateOrderStatus listener must have found the order (under the order's
        // own store) and advanced it to processing. If markPaid had left the
        // ambient default-store tenant in place, the scoped lookup would have
        // missed the order and the status would still be 'pending'.
        $this->assertSame('processing', $order->fresh()->status);

        // And CurrentStore now reflects the order's store.
        $this->assertSame($this->otherStore->id, app(CurrentStore::class)->id());
    }
}

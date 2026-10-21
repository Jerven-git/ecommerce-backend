<?php

namespace Tests\Feature\Contracts;

use App\Models\Backorder;
use App\Models\GiftCardDenomination;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\User;
use App\Payments\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CommerceResponseContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_creation_keeps_its_success_and_breakdown_envelope(): void
    {
        $product = Product::factory()->create([
            'price' => 25,
            'stock' => 10,
        ]);

        $this->postJson('/api/v1/orders', [
            'customer_name' => 'Contract Customer',
            'customer_email' => 'contract@example.com',
            'delivery_method' => 'pickup',
            'shipping_address' => '',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
        ])->assertCreated()
            ->assertJsonStructure([
                'message',
                'data' => [
                    'order' => [
                        'id',
                        'customer_name',
                        'customer_email',
                        'delivery_method',
                        'subtotal',
                        'tax_amount',
                        'shipping_amount',
                        'total_amount',
                        'currency',
                        'status',
                        'has_backorder_items',
                        'items',
                    ],
                    'breakdown' => [
                        'raw_subtotal',
                        'tax',
                        'shipping',
                        'discount_code',
                        'discount_amount',
                        'final_total',
                    ],
                ],
            ])
            ->assertJsonPath('message', 'Order created successfully')
            ->assertJsonPath('data.order.status', 'pending');
    }

    public function test_order_validation_keeps_laravels_error_envelope(): void
    {
        $this->postJson('/api/v1/orders', [])
            ->assertUnprocessable()
            ->assertJsonStructure([
                'message',
                'errors' => [
                    'customer_name',
                    'customer_email',
                    'delivery_method',
                    'items',
                ],
            ]);
    }

    public function test_gift_card_checkout_keeps_its_order_envelope(): void
    {
        $denomination = GiftCardDenomination::create([
            'amount' => 75,
            'label' => '$75',
            'is_enabled' => true,
            'sort_order' => 1,
        ]);

        $this->postJson('/api/v1/orders', [
            'customer_name' => 'Gift Buyer',
            'customer_email' => 'buyer@example.com',
            'delivery_method' => 'gift_card',
            'payment_method' => 'stripe',
            'gift_card_denomination_id' => $denomination->id,
            'gift_card_recipient_email' => 'recipient@example.com',
        ])->assertCreated()
            ->assertJsonStructure([
                'message',
                'data' => [
                    'order' => [
                        'id',
                        'customer_name',
                        'customer_email',
                        'delivery_method',
                        'subtotal',
                        'tax_amount',
                        'shipping_amount',
                        'total_amount',
                        'currency',
                        'status',
                    ],
                ],
            ])
            ->assertJsonPath('message', 'Gift card order created successfully')
            ->assertJsonPath('data.order.delivery_method', 'gift_card')
            ->assertJsonPath('data.order.total_amount', '75.00');
    }

    public function test_order_index_keeps_pagination_and_status_counts_at_the_root(): void
    {
        $admin = $this->admin();
        Order::factory()->create(['status' => 'pending']);
        Order::factory()->create(['status' => 'processing']);

        $this->actingAs($admin)
            ->getJson('/api/v1/orders?per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonStructure([
                'current_page',
                'data',
                'first_page_url',
                'from',
                'last_page',
                'last_page_url',
                'links',
                'next_page_url',
                'path',
                'per_page',
                'prev_page_url',
                'to',
                'total',
                'status_counts',
            ])
            ->assertJsonPath('per_page', 1)
            ->assertJsonPath('total', 2)
            ->assertJsonPath('status_counts.pending', 1)
            ->assertJsonPath('status_counts.processing', 1);
    }

    public function test_payment_status_keeps_its_flat_opaque_token_contract(): void
    {
        $order = Order::factory()->create(['status' => 'processing']);
        $payment = app(PaymentService::class)->createPending($order, 'stripe', [
            'provider_ref' => 'pi_contract',
        ]);

        $this->getJson("/api/v1/payments/{$payment->id}?payment_token={$payment->public_token}")
            ->assertOk()
            ->assertExactJson([
                'id' => $payment->id,
                'status' => 'pending',
                'provider' => 'stripe',
                'order_id' => $order->id,
                'order_status' => 'processing',
            ]);
    }

    public function test_manual_payment_lifecycle_keeps_its_response_envelopes(): void
    {
        $admin = $this->admin();
        $order = Order::factory()->create(['status' => 'pending']);
        Payment::create([
            'order_id' => $order->id,
            'provider' => 'cash',
            'status' => 'pending',
            'amount' => 2500,
            'currency' => 'USD',
        ]);

        $this->actingAs($admin)
            ->postJson("/api/v1/orders/{$order->id}/confirm-payment")
            ->assertOk()
            ->assertJsonPath('message', 'Payment confirmed')
            ->assertJsonPath('data.payment.status', 'paid');

        $this->actingAs($admin)
            ->postJson("/api/v1/orders/{$order->id}/undo-payment")
            ->assertOk()
            ->assertJsonPath('message', 'Payment reverted to pending')
            ->assertJsonPath('data.payment.status', 'pending');
    }

    public function test_cancel_and_refund_keeps_its_manual_refund_contract(): void
    {
        Mail::fake();

        $admin = $this->admin();
        $order = Order::factory()->create([
            'status' => 'processing',
            'customer_email' => 'refund@example.com',
        ]);
        Payment::create([
            'order_id' => $order->id,
            'provider' => 'stripe',
            'status' => 'paid',
            'amount' => 2500,
            'currency' => 'USD',
        ]);

        $this->actingAs($admin)
            ->postJson("/api/v1/orders/{$order->id}/cancel-refund")
            ->assertOk()
            ->assertJsonPath('message', 'Order cancelled. Please process the refund through your payment provider.')
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.payment.status', 'refunded');
    }

    public function test_public_tracking_keeps_the_shipment_and_restricted_order_envelope(): void
    {
        $order = Order::factory()->create([
            'customer_name' => 'Tracking Customer',
            'status' => 'shipped',
        ]);
        $shipment = Shipment::create([
            'order_id' => $order->id,
            'tracking_number' => 'SSU-CONTRACT-TRACK',
            'carrier' => 'Contract Carrier',
            'status' => 'in_transit',
            'shipped_at' => now(),
        ]);

        $this->getJson('/api/v1/tracking/SSU-CONTRACT-TRACK')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'order_id',
                    'tracking_number',
                    'carrier',
                    'status',
                    'shipped_at',
                    'delivered_at',
                    'order' => [
                        'id',
                        'customer_name',
                        'status',
                        'created_at',
                    ],
                ],
            ])
            ->assertJsonPath('data.id', $shipment->id)
            ->assertJsonMissingPath('data.order.customer_email')
            ->assertJsonMissingPath('data.order.shipping_address');
    }

    public function test_backorder_token_keeps_its_checkout_summary_envelope(): void
    {
        $product = Product::factory()->create([
            'name' => 'Contract Product',
            'price' => 50,
            'stock' => 5,
        ]);
        $order = Order::factory()->create([
            'customer_name' => 'Backorder Customer',
            'customer_email' => 'backorder@example.com',
            'status' => 'backorder_notified',
            'has_backorder_items' => true,
        ]);
        Backorder::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'status' => 'notified',
            'charge_policy' => 'charged_later',
            'payment_token' => 'contract_token_1234567890abcdef1234567890abcdef1234567890abcdef12345678',
            'token_expires_at' => now()->addDay(),
        ]);

        $this->getJson('/api/v1/backorders/pay/contract_token_1234567890abcdef1234567890abcdef1234567890abcdef12345678')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'product_name',
                    'product_price',
                    'quantity',
                    'subtotal',
                    'tax_amount',
                    'tax_rate',
                    'tax_name',
                    'shipping_amount',
                    'total',
                    'customer_name',
                    'customer_email',
                    'order_id',
                    'expires_at',
                    'stock_available',
                ],
            ])
            ->assertJsonPath('data.product_name', 'Contract Product')
            ->assertJsonPath('data.quantity', 2)
            ->assertJsonPath('data.stock_available', true);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'admin']);
        $admin->roles()->attach($role);

        return $admin;
    }
}

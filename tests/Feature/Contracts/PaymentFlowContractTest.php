<?php

namespace Tests\Feature\Contracts;

use App\Models\Order;
use App\Models\Payment;
use App\Payments\Contracts\ChecksPaymentStatus;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\GatewayManager;
use App\Payments\PaymentService;
use App\Payments\PayPalToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

class PaymentFlowContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_redirect_payment_initiation_keeps_its_flat_response_contract(): void
    {
        $order = Order::factory()->create(['total_amount' => 42.50, 'currency' => 'USD']);
        $this->bindGateway(new ContractPaymentGateway('paypal', [
            'provider_ref' => 'paypal-order-contract',
            'approval_url' => 'https://paypal.example/approve',
            'request_id' => 'request-contract',
        ]));

        $response = $this->postJson("/api/v1/orders/{$order->id}/pay", [
            'provider' => 'paypal',
        ])->assertOk()
            ->assertJsonStructure([
                'payment_id',
                'payment_token',
                'provider',
                'provider_ref',
                'redirect_url',
            ])
            ->assertJsonPath('provider', 'paypal')
            ->assertJsonPath('provider_ref', 'paypal-order-contract')
            ->assertJsonPath('redirect_url', 'https://paypal.example/approve');

        $payment = Payment::findOrFail($response->json('payment_id'));

        $this->assertSame('pending', $payment->status);
        $this->assertSame($payment->public_token, $response->json('payment_token'));
        $this->assertSame(4250, $payment->amount);
        $this->assertSame('request-contract', $payment->meta['request_id']);
    }

    public function test_stripe_intent_keeps_its_flat_response_contract(): void
    {
        $order = Order::factory()->create();
        $this->bindGateway(new ContractPaymentGateway('stripe', [
            'provider_ref' => 'pi_contract_start',
            'client_secret' => 'pi_contract_start_secret',
        ]));

        $response = $this->postJson("/api/v1/orders/{$order->id}/stripe/intent")
            ->assertOk()
            ->assertJsonStructure(['payment_id', 'payment_token', 'client_secret'])
            ->assertJsonPath('client_secret', 'pi_contract_start_secret');

        $payment = Payment::findOrFail($response->json('payment_id'));

        $this->assertSame('stripe', $payment->provider);
        $this->assertSame('pi_contract_start', $payment->provider_ref);
        $this->assertSame($payment->public_token, $response->json('payment_token'));
    }

    public function test_status_poll_merges_gateway_meta_and_finalizes_a_payment(): void
    {
        $order = Order::factory()->create(['status' => 'pending']);
        $payment = app(PaymentService::class)->createPending($order, 'stripe', [
            'provider_ref' => 'pi_contract_poll',
        ]);
        $payment->forceFill(['created_at' => now()->subSeconds(5)])->save();

        $this->bindGateway(new ContractPollingGateway('stripe', [
            'paid' => true,
            'meta' => ['gateway_status' => 'succeeded'],
        ]));

        $this->getJson("/api/v1/payments/{$payment->id}?payment_token={$payment->public_token}")
            ->assertOk()
            ->assertExactJson([
                'id' => $payment->id,
                'status' => 'paid',
                'provider' => 'stripe',
                'order_id' => $order->id,
                'order_status' => 'processing',
            ]);

        $this->assertSame('succeeded', $payment->fresh()->meta['gateway_status']);
    }

    public function test_paypal_capture_keeps_its_success_contract(): void
    {
        $order = Order::factory()->create(['status' => 'pending']);
        $payment = app(PaymentService::class)->createPending($order, 'paypal', [
            'provider_ref' => 'paypal-capture-contract',
        ]);

        $this->mock(PayPalToken::class, function (MockInterface $mock) {
            $mock->shouldReceive('get')->once()->andReturn('paypal-access-token');
        });
        Http::fake([
            'api-m.sandbox.paypal.com/v2/checkout/orders/paypal-capture-contract/capture' => Http::response([
                'status' => 'COMPLETED',
                'purchase_units' => [[
                    'payments' => ['captures' => [[
                        'id' => 'paypal-capture-id',
                        'status' => 'COMPLETED',
                    ]]],
                ]],
            ]),
        ]);

        $this->postJson('/api/v1/paypal/capture', ['token' => 'paypal-capture-contract'])
            ->assertOk()
            ->assertExactJson([
                'payment_id' => $payment->id,
                'payment_token' => $payment->public_token,
                'status' => 'paid',
                'order_status' => 'processing',
                'capture_id' => 'paypal-capture-id',
            ]);

        $this->assertSame('paid', $payment->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_paypal_return_and_cancel_keep_their_redirect_contracts(): void
    {
        config()->set('app.frontend_url', 'https://storefront.example');

        $this->get('/api/v1/paypal/return')
            ->assertRedirect('https://storefront.example/checkout/failed?reason=missing_token');

        $this->get('/api/v1/paypal/return?token=unknown-paypal-order')
            ->assertRedirect('https://storefront.example/checkout/failed?reason=payment_not_found');

        $this->get('/api/v1/paypal/cancel')
            ->assertRedirect('https://storefront.example/checkout?cancelled=1');
    }

    private function bindGateway(PaymentGateway $gateway): void
    {
        $this->app->instance(GatewayManager::class, new GatewayManager([
            $gateway->key() => $gateway,
        ]));
    }
}

class ContractPaymentGateway implements PaymentGateway
{
    public function __construct(
        private readonly string $provider,
        private readonly array $init,
    ) {}

    public function key(): string
    {
        return $this->provider;
    }

    public function createPayment(Order $order, array $meta = []): array
    {
        return $this->init;
    }
}

class ContractPollingGateway extends ContractPaymentGateway implements ChecksPaymentStatus
{
    public function __construct(string $provider, private readonly array $result)
    {
        parent::__construct($provider, []);
    }

    public function verifyPayment(Payment $payment): array
    {
        return $this->result;
    }
}

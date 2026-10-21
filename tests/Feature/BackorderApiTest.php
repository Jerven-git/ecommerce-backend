<?php

namespace Tests\Feature;

use App\Models\Backorder;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\SiteConfig;
use App\Models\Store;
use App\Models\User;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\GatewayManager;
use App\Payments\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class BackorderApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Order $order;

    private Product $product;

    private Backorder $backorder;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->admin = User::factory()->create();
        $role = Role::create(['name' => 'admin']);
        $this->admin->roles()->attach($role);

        $this->product = Product::factory()->create([
            'price' => 50.00,
            'stock' => 5,
        ]);

        $this->order = Order::factory()->create([
            'status' => 'backorder_awaiting_stock',
            'has_backorder_items' => true,
        ]);

        $this->backorder = Backorder::create([
            'order_id' => $this->order->id,
            'product_id' => $this->product->id,
            'quantity' => 2,
            'status' => 'awaiting_stock',
            'charge_policy' => 'charged_later',
        ]);
    }

    // ─────────────────────────────────────────
    // Listing
    // ─────────────────────────────────────────

    public function test_unauthenticated_cannot_list_backorders(): void
    {
        $this->getJson('/api/v1/backorders')->assertStatus(401);
    }

    public function test_admin_can_list_backorders(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/v1/backorders')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_admin_can_filter_backorders_by_status(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/v1/backorders?status=awaiting_stock')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($this->admin)
            ->getJson('/api/v1/backorders?status=paid')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_admin_can_show_backorder(): void
    {
        $this->actingAs($this->admin)
            ->getJson("/api/v1/backorders/{$this->backorder->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $this->backorder->id);
    }

    // ─────────────────────────────────────────
    // Notify
    // ─────────────────────────────────────────

    public function test_admin_can_notify_backorder(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/v1/backorders/{$this->backorder->id}/notify")
            ->assertOk()
            ->assertJsonPath('data.status', 'notified');

        $this->backorder->refresh();
        $this->assertNotNull($this->backorder->payment_token);
        $this->assertNotNull($this->backorder->token_expires_at);
    }

    public function test_cannot_notify_paid_backorder(): void
    {
        $this->backorder->update(['status' => 'paid']);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/backorders/{$this->backorder->id}/notify")
            ->assertStatus(422);
    }

    public function test_cannot_notify_if_insufficient_stock(): void
    {
        $this->product->update(['stock' => 0]);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/backorders/{$this->backorder->id}/notify")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Insufficient stock to send payment link. Available: 0, required: 2.']);
    }

    // ─────────────────────────────────────────
    // Resend
    // ─────────────────────────────────────────

    public function test_admin_can_resend_payment_link(): void
    {
        $this->backorder->update([
            'status' => 'notified',
            'payment_token' => 'old_token',
            'token_expires_at' => now()->addHours(24),
        ]);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/backorders/{$this->backorder->id}/resend")
            ->assertOk();

        $this->backorder->refresh();
        $this->assertNotEquals('old_token', $this->backorder->payment_token);
    }

    public function test_cannot_resend_for_awaiting_stock(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/v1/backorders/{$this->backorder->id}/resend")
            ->assertStatus(422);
    }

    // ─────────────────────────────────────────
    // Cancel
    // ─────────────────────────────────────────

    public function test_admin_can_cancel_backorder(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/v1/backorders/{$this->backorder->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertNull($this->backorder->fresh()->payment_token);
    }

    public function test_cannot_cancel_paid_backorder(): void
    {
        $this->backorder->update(['status' => 'paid']);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/backorders/{$this->backorder->id}/cancel")
            ->assertStatus(422);
    }

    // ─────────────────────────────────────────
    // Token Verification (Public)
    // ─────────────────────────────────────────

    public function test_valid_token_returns_backorder_details(): void
    {
        $this->backorder->update([
            'status' => 'notified',
            'payment_token' => 'valid_token_1234567890abcdef1234567890abcdef1234567890abcdef1234567890ab',
            'token_expires_at' => now()->addHours(24),
        ]);

        $this->getJson('/api/v1/backorders/pay/valid_token_1234567890abcdef1234567890abcdef1234567890abcdef1234567890ab')
            ->assertOk()
            ->assertJsonStructure(['data' => ['id', 'product_name', 'quantity', 'total']]);
    }

    public function test_invalid_token_returns_404(): void
    {
        $this->getJson('/api/v1/backorders/pay/nonexistent_token_value')
            ->assertStatus(404);
    }

    public function test_expired_token_returns_410(): void
    {
        $this->backorder->update([
            'status' => 'notified',
            'payment_token' => 'expired_token_234567890abcdef1234567890abcdef1234567890abcdef1234567890',
            'token_expires_at' => now()->subHour(),
        ]);

        $this->getJson('/api/v1/backorders/pay/expired_token_234567890abcdef1234567890abcdef1234567890abcdef1234567890')
            ->assertStatus(410);
    }

    public function test_public_token_cannot_cross_the_storefront_boundary(): void
    {
        $foreignStore = Store::factory()->create(['slug' => 'foreign-backorder']);
        $foreignProduct = Product::factory()->create(['store_id' => $foreignStore->id]);
        $foreignOrder = Order::factory()->create(['store_id' => $foreignStore->id]);
        Backorder::create([
            'order_id' => $foreignOrder->id,
            'product_id' => $foreignProduct->id,
            'quantity' => 1,
            'status' => 'notified',
            'charge_policy' => 'charged_later',
            'payment_token' => 'foreign_store_backorder_token',
            'token_expires_at' => now()->addDay(),
        ]);

        $this->getJson('/api/v1/backorders/pay/foreign_store_backorder_token')
            ->assertNotFound();
    }

    public function test_public_backorder_payment_keeps_its_redirect_contract(): void
    {
        $this->backorder->update([
            'status' => 'notified',
            'payment_token' => 'payable_backorder_token',
            'token_expires_at' => now()->addDay(),
            'stock_reserved' => true,
        ]);
        $this->app->instance(GatewayManager::class, new GatewayManager([
            'paypal' => new BackorderContractGateway,
        ]));

        $response = $this->postJson('/api/v1/backorders/pay/payable_backorder_token', [
            'payment_method' => 'paypal',
        ])->assertOk()
            ->assertJsonStructure(['data' => [
                'order_id',
                'amount',
                'backorder_id',
                'payment_id',
                'payment_token',
                'redirect_url',
            ]])
            ->assertJsonPath('data.order_id', $this->order->id)
            ->assertJsonPath('data.amount', 100)
            ->assertJsonPath('data.backorder_id', $this->backorder->id)
            ->assertJsonPath('data.redirect_url', 'https://paypal.example/backorder');

        $this->assertDatabaseHas('payments', [
            'id' => $response->json('data.payment_id'),
            'order_id' => $this->order->id,
            'provider' => 'paypal',
            'status' => 'pending',
        ]);
    }

    public function test_public_confirmation_and_admin_mark_paid_keep_their_contracts(): void
    {
        $this->backorder->update([
            'status' => 'notified',
            'payment_token' => 'confirmable_backorder_token',
            'token_expires_at' => now()->addDay(),
            'stock_reserved' => true,
        ]);

        $this->postJson('/api/v1/backorders/pay/confirmable_backorder_token/confirm')
            ->assertOk()
            ->assertExactJson([
                'message' => 'Order confirmed. You will be contacted for payment.',
                'data' => ['status' => 'confirmed'],
            ]);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/backorders/{$this->backorder->id}/mark-paid")
            ->assertOk()
            ->assertJsonPath('message', 'Backorder marked as paid')
            ->assertJsonPath('data.status', 'paid');

        $this->assertSame('processing', $this->order->fresh()->status);
        $this->assertFalse($this->backorder->fresh()->stock_reserved);
    }

    public function test_cancel_releases_reserved_stock_once(): void
    {
        $this->product->update(['stock' => 3]);
        $this->backorder->update([
            'status' => 'notified',
            'stock_reserved' => true,
        ]);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/backorders/{$this->backorder->id}/cancel")
            ->assertOk();

        $this->assertSame(5, $this->product->fresh()->stock);
        $this->assertFalse($this->backorder->fresh()->stock_reserved);
    }

    public function test_confirmed_provider_payment_fulfils_the_backorder_once(): void
    {
        $this->product->update(['stock' => 3]);
        $this->backorder->update([
            'status' => 'notified',
            'payment_token' => 'provider_payment_token',
            'token_expires_at' => now()->addDay(),
            'stock_reserved' => true,
        ]);
        $payment = app(PaymentService::class)->createPending($this->order, 'stripe', [
            'provider_ref' => 'pi_backorder_fulfilment',
            'backorder_id' => $this->backorder->id,
        ]);

        app(PaymentService::class)->markPaid($payment);
        app(PaymentService::class)->markPaid($payment->fresh());

        $this->assertSame('paid', $this->backorder->fresh()->status);
        $this->assertNull($this->backorder->fresh()->payment_token);
        $this->assertSame(3, $this->product->fresh()->stock);
        $this->assertSame('processing', $this->order->fresh()->status);
    }

    public function test_expiry_command_releases_reserved_stock(): void
    {
        $this->product->update(['stock' => 3]);
        $this->backorder->update([
            'status' => 'notified',
            'payment_token' => 'expired_command_token',
            'token_expires_at' => now()->subMinute(),
            'stock_reserved' => true,
        ]);

        $this->artisan('backorders:expire-tokens')
            ->expectsOutput('Expired 1 backorder payment links.')
            ->assertSuccessful();

        $this->assertSame('expired', $this->backorder->fresh()->status);
        $this->assertNull($this->backorder->fresh()->payment_token);
        $this->assertSame(5, $this->product->fresh()->stock);
    }

    // ─────────────────────────────────────────
    // Settings
    // ─────────────────────────────────────────

    public function test_admin_can_get_backorder_settings(): void
    {
        SiteConfig::create(['backorder_enabled' => true, 'backorder_payment_link_expiry_hours' => 48]);

        $this->actingAs($this->admin)
            ->getJson('/api/v1/backorder-settings')
            ->assertOk()
            ->assertJsonPath('data.backorder_enabled', true)
            ->assertJsonPath('data.backorder_payment_link_expiry_hours', 48);
    }

    public function test_admin_can_update_backorder_settings(): void
    {
        SiteConfig::create([]);

        $this->actingAs($this->admin)
            ->patchJson('/api/v1/backorder-settings', [
                'backorder_enabled' => true,
                'backorder_payment_link_expiry_hours' => 72,
            ])
            ->assertOk()
            ->assertJsonPath('data.backorder_payment_link_expiry_hours', 72);
    }

    public function test_backorder_settings_validates_expiry_range(): void
    {
        SiteConfig::create([]);

        $this->actingAs($this->admin)
            ->patchJson('/api/v1/backorder-settings', [
                'backorder_enabled' => true,
                'backorder_payment_link_expiry_hours' => 0,
            ])
            ->assertStatus(422);
    }
}

class BackorderContractGateway implements PaymentGateway
{
    public function key(): string
    {
        return 'paypal';
    }

    public function createPayment(Order $order, array $meta = []): array
    {
        return [
            'provider_ref' => 'paypal-backorder-contract',
            'approval_url' => 'https://paypal.example/backorder',
        ];
    }
}

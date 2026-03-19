<?php

namespace Tests\Feature;

use App\Models\Backorder;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\SiteConfig;
use App\Models\User;
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

<?php

namespace Tests\Feature;

use App\Models\Discount;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiscountApiTest extends TestCase
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

    // ─────────────────────────────────────────
    // CRUD
    // ─────────────────────────────────────────

    public function test_admin_can_create_discount(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/discounts', [
                'code' => 'SUMMER20',
                'type' => 'percentage',
                'value' => 20,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.code', 'SUMMER20');
    }

    public function test_unauthenticated_cannot_create_discount(): void
    {
        $this->postJson('/api/v1/discounts', [
            'code' => 'TEST',
            'type' => 'fixed',
            'value' => 5,
        ])->assertStatus(401);
    }

    public function test_unauthenticated_cannot_list_discounts(): void
    {
        $this->getJson('/api/v1/discounts')->assertStatus(401);
    }

    public function test_unauthenticated_cannot_show_discount(): void
    {
        $discount = Discount::factory()->create();
        $this->getJson("/api/v1/discounts/{$discount->id}")->assertStatus(401);
    }

    public function test_discount_code_must_be_unique(): void
    {
        Discount::factory()->create(['code' => 'UNIQUE']);

        $this->actingAs($this->admin)
            ->postJson('/api/v1/discounts', [
                'code' => 'UNIQUE',
                'type' => 'fixed',
                'value' => 5,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_percentage_discount_cannot_exceed_100(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/discounts', [
                'code' => 'TOOMUCH',
                'type' => 'percentage',
                'value' => 150,
            ])
            ->assertStatus(422);
    }

    public function test_admin_can_update_discount(): void
    {
        $discount = Discount::factory()->create();

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/discounts/{$discount->id}", [
                'value' => 30,
            ])
            ->assertOk();

        $this->assertEquals(30, $discount->fresh()->value);
    }

    public function test_admin_can_delete_discount(): void
    {
        $discount = Discount::factory()->create();

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/discounts/{$discount->id}")
            ->assertOk();

        $this->assertDatabaseMissing('discounts', ['id' => $discount->id]);
    }

    // ─────────────────────────────────────────
    // Validate Endpoint
    // ─────────────────────────────────────────

    public function test_can_validate_active_discount(): void
    {
        Discount::factory()->create([
            'code' => 'VALID10',
            'type' => 'fixed',
            'value' => 10,
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/discounts/validate', [
            'code' => 'VALID10',
            'order_amount' => 50,
        ])->assertOk()
          ->assertJsonPath('valid', true);
    }

    public function test_expired_discount_is_invalid(): void
    {
        Discount::factory()->expired()->create(['code' => 'EXPIRED']);

        $this->postJson('/api/v1/discounts/validate', [
            'code' => 'EXPIRED',
            'order_amount' => 50,
        ])->assertStatus(422)
          ->assertJsonPath('valid', false);
    }

    public function test_nonexistent_discount_returns_404(): void
    {
        $this->postJson('/api/v1/discounts/validate', [
            'code' => 'DOESNOTEXIST',
            'order_amount' => 50,
        ])->assertStatus(404);
    }

    // ─────────────────────────────────────────
    // Sanitization
    // ─────────────────────────────────────────

    public function test_discount_code_is_uppercased_and_trimmed(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/discounts', [
                'code' => '  summer20  ',
                'type' => 'percentage',
                'value' => 20,
            ])
            ->assertStatus(201);

        $this->assertEquals('SUMMER20', Discount::latest()->first()->code);
    }

    public function test_xss_stripped_from_discount_description(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/discounts', [
                'code' => 'CLEAN',
                'type' => 'fixed',
                'value' => 5,
                'description' => '<script>alert(1)</script>Summer sale',
            ])
            ->assertStatus(201);

        $discount = Discount::where('code', 'CLEAN')->first();
        $this->assertStringNotContainsString('<script>', $discount->description);
        $this->assertStringContainsString('Summer sale', $discount->description);
    }
}

<?php

namespace Tests\Feature;

use App\Http\Middleware\SessionLifetimeMiddleware;
use App\Models\Currency;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrencyApiTest extends TestCase
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

    private function asAdmin(): self
    {
        return $this->actingAs($this->admin)
            ->withoutMiddleware(SessionLifetimeMiddleware::class);
    }

    public function test_public_index_returns_only_enabled_currencies(): void
    {
        Currency::factory()->base()->create();
        Currency::factory()->create(['code' => 'AUD', 'is_enabled' => true, 'sort_order' => 10]);
        Currency::factory()->create(['code' => 'XYZ', 'is_enabled' => false]);

        $this->getJson('/api/v1/currencies')
            ->assertOk()
            ->assertJsonPath('base', 'USD')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.code', 'USD')
            ->assertJsonPath('data.1.code', 'AUD');
    }

    public function test_admin_can_create_currency(): void
    {
        $this->asAdmin()
            ->postJson('/api/v1/currencies', [
                'code' => 'eur',
                'name' => 'Euro',
                'symbol' => '€',
                'rate' => 0.92,
            ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'EUR')
            ->assertJsonPath('data.symbol', '€');
    }

    public function test_admin_can_promote_currency_to_base_and_demote_previous(): void
    {
        $usd = Currency::factory()->base()->create();
        $aud = Currency::factory()->create(['code' => 'AUD', 'rate' => 1.5]);

        $this->asAdmin()
            ->patchJson("/api/v1/currencies/{$aud->id}", ['is_base' => true])
            ->assertOk()
            ->assertJsonPath('data.is_base', true)
            ->assertJsonPath('data.rate', '1.00000000');

        $this->assertFalse($usd->fresh()->is_base);
        $this->assertTrue($aud->fresh()->is_base);
    }

    public function test_cannot_delete_base_currency(): void
    {
        $usd = Currency::factory()->base()->create();

        $this->asAdmin()
            ->deleteJson("/api/v1/currencies/{$usd->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('currencies', ['id' => $usd->id]);
    }

    public function test_code_must_be_unique(): void
    {
        Currency::factory()->base()->create();

        $this->asAdmin()
            ->postJson('/api/v1/currencies', [
                'code' => 'USD',
                'name' => 'Dup',
                'symbol' => '$',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_convert_from_base_rounds_to_decimal_places(): void
    {
        $jpy = Currency::factory()->create([
            'code' => 'JPY',
            'decimal_places' => 0,
            'rate' => 152.5,
        ]);

        $this->assertSame(15250.0, $jpy->convertFromBase(100.0));
        $this->assertSame(305.0, $jpy->convertFromBase(2.0));
    }
}

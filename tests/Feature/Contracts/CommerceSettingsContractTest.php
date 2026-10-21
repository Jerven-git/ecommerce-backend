<?php

namespace Tests\Feature\Contracts;

use App\Http\Middleware\SessionLifetimeMiddleware;
use App\Models\GiftCard;
use App\Models\Order;
use App\Models\Role;
use App\Models\SiteConfig;
use App\Models\TaxRule;
use App\Models\User;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommerceSettingsContractTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->admin->roles()->attach(Role::firstOrCreate(['name' => 'admin']));
        $this->withoutMiddleware(SessionLifetimeMiddleware::class);
    }

    public function test_shipping_settings_and_quote_keep_their_response_contracts(): void
    {
        $this->actingAs($this->admin)
            ->patchJson('/api/v1/shipping-settings', [
                'express_post_fee' => 12,
                'free_shipping_threshold' => 100,
                'store_country' => 'Australia',
                'store_state' => 'Victoria',
                'store_city' => 'Melbourne',
                'zones' => [[
                    'zone_type' => 'own_city',
                    'enabled' => true,
                    'base_rate' => 5,
                    'per_kg_rate' => 2,
                    'per_cbm_rate' => 0,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Shipping settings updated successfully')
            ->assertJsonPath('data.express_post_fee', 12);

        $this->actingAs($this->admin)
            ->getJson('/api/v1/shipping-settings')
            ->assertOk()
            ->assertJsonPath('data.zones.0.zone_type', 'own_city');

        $this->postJson('/api/v1/shipping/calculate', [
            'country' => 'Australia',
            'state' => 'Victoria',
            'city' => 'Melbourne',
            'weight' => 2,
            'order_amount' => 50,
        ])
            ->assertOk()
            ->assertJsonPath('base_shipping', 5)
            ->assertJsonPath('weight_fee', 4)
            ->assertJsonPath('total', 9)
            ->assertJsonPath('zone', 'own_city');
    }

    public function test_tax_settings_rules_and_cart_calculation_keep_their_contracts(): void
    {
        $this->actingAs($this->admin)
            ->patchJson('/api/v1/tax-settings', [
                'tax_enabled' => true,
                'tax_rate' => 10,
                'tax_display_mode' => 'exclusive',
                'tax_name' => 'GST',
            ])
            ->assertOk()
            ->assertJsonPath('data.tax_name', 'GST');

        $rule = $this->actingAs($this->admin)
            ->postJson('/api/v1/tax-rules', [
                'region_type' => 'country',
                'country' => 'Australia',
                'tax_rate' => 15,
                'tax_name' => 'Regional GST',
                'tax_display_mode' => 'exclusive',
                'enabled' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.priority', 1)
            ->json('data');

        $this->postJson('/api/v1/tax/calculate-cart', [
            'items' => [['price' => 100, 'quantity' => 1]],
            'shipping_amount' => 10,
            'country' => 'Australia',
        ])
            ->assertOk()
            ->assertJsonPath('tax_amount', 16.5)
            ->assertJsonPath('total', 126.5)
            ->assertJsonPath('rule_id', $rule['id'])
            ->assertJsonPath('has_regional_rules', true);
    }

    public function test_gift_card_admin_and_public_responses_remain_compatible(): void
    {
        SiteConfig::create(['modules_enabled' => ['gift_cards' => true]]);

        $this->actingAs($this->admin)
            ->postJson('/api/v1/gift-card-denominations', [
                'amount' => 50,
                'label' => '$50',
                'is_enabled' => true,
                'sort_order' => 1,
            ])
            ->assertCreated()
            ->assertJsonPath('data.amount', '50.00');

        $card = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/gift-cards', [
                'amount' => 75,
                'recipient_email' => 'recipient@example.com',
                'purchaser_name' => 'Buyer',
                'purchaser_email' => 'buyer@example.com',
                'currency' => 'aud',
            ])
            ->assertCreated()
            ->assertJsonPath('data.currency', 'AUD')
            ->assertJsonPath('data.status', GiftCard::STATUS_ACTIVE)
            ->json('data');

        $this->getJson('/api/v1/gift-card-denominations')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->postJson('/api/v1/gift-cards/validate', ['code' => strtolower($card['code'])])
            ->assertOk()
            ->assertJsonPath('data.code', $card['code'])
            ->assertJsonPath('data.balance', 75)
            ->assertJsonPath('data.currency', 'AUD');
    }

    public function test_tax_report_keeps_its_grouped_response_envelope(): void
    {
        $rule = TaxRule::create([
            'region_type' => 'country',
            'country' => 'Australia',
            'tax_rate' => 10,
            'tax_name' => 'GST',
            'tax_display_mode' => 'exclusive',
            'enabled' => true,
            'priority' => 1,
        ]);
        Order::factory()->create([
            'country' => 'Australia',
            'state' => 'Victoria',
            'tax_region' => 'Victoria, Australia',
            'tax_rule_id' => $rule->id,
            'subtotal' => 100,
            'tax_amount' => 10,
            'total_amount' => 110,
            'status' => 'completed',
        ]);

        $this->actingAs($this->admin)
            ->getJson('/api/v1/tax-report')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.region_label', 'Victoria, Australia')
            ->assertJsonPath('data.0.order_count', 1)
            ->assertJsonPath('data.0.subtotal', 100)
            ->assertJsonPath('data.0.tax_collected', 10)
            ->assertJsonPath('data.0.total', 110)
            ->assertJsonPath('data.0.tax_name', 'GST');
    }

    public function test_tax_rule_sync_rejects_an_id_owned_by_another_store(): void
    {
        $otherAdmin = User::factory()->create(['is_admin' => true]);
        $otherAdmin->store()->associate(\App\Models\Store::factory()->create())->save();
        $otherAdmin->roles()->attach(Role::firstOrCreate(['name' => 'admin']));

        $foreignRule = $this->actingAs($otherAdmin)
            ->postJson('/api/v1/tax-rules', [
                'region_type' => 'all',
                'tax_rate' => 20,
                'tax_name' => 'Foreign Tax',
                'tax_display_mode' => 'exclusive',
                'enabled' => true,
            ])
            ->assertCreated()
            ->json('data');

        app(CurrentStore::class)->clear();

        $this->actingAs($this->admin)
            ->postJson('/api/v1/tax-rules/sync', ['rules' => [[
                'id' => $foreignRule['id'],
                'region_type' => 'all',
                'tax_rate' => 5,
                'tax_name' => 'Wrong Store',
                'tax_display_mode' => 'exclusive',
                'enabled' => true,
            ]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rules.0.id');

        $this->assertSame('Foreign Tax', TaxRule::withoutGlobalScopes()->findOrFail($foreignRule['id'])->tax_name);
    }
}

<?php

namespace Tests\Feature\Tenancy;

use App\Models\Category;
use App\Models\CommissionRequest;
use App\Models\Concerns\BelongsToStore;
use App\Models\Discount;
use App\Models\GiftCard;
use App\Models\GiftCardDenomination;
use App\Models\Order;
use App\Models\PaymentSetting;
use App\Models\Post;
use App\Models\PostCategory;
use App\Models\Product;
use App\Models\Role;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ShippingSetting;
use App\Models\ShippingZone;
use App\Models\Store;
use App\Models\Subscriber;
use App\Models\TaxRule;
use App\Models\TaxSetting;
use App\Models\User;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

class TenantPersistenceBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_tenant_models_share_the_store_ownership_concern(): void
    {
        $models = [
            Category::class,
            CommissionRequest::class,
            Discount::class,
            GiftCard::class,
            GiftCardDenomination::class,
            Order::class,
            PaymentSetting::class,
            Post::class,
            PostCategory::class,
            Product::class,
            Service::class,
            ServiceCategory::class,
            ShippingSetting::class,
            ShippingZone::class,
            Subscriber::class,
            TaxRule::class,
            TaxSetting::class,
        ];

        foreach ($models as $model) {
            $this->assertContains(
                BelongsToStore::class,
                class_uses_recursive($model),
                "{$model} must use the shared tenant ownership concern.",
            );
        }
    }

    public function test_direct_tenant_tables_require_a_cascading_store_foreign_key(): void
    {
        $tables = [
            'categories',
            'commission_requests',
            'discounts',
            'gift_cards',
            'gift_card_denominations',
            'orders',
            'payment_settings',
            'posts',
            'post_categories',
            'products',
            'services',
            'service_categories',
            'shipping_settings',
            'shipping_zones',
            'subscribers',
            'tax_rules',
            'tax_settings',
        ];

        foreach ($tables as $table) {
            $storeColumn = collect(Schema::getColumns($table))->firstWhere('name', 'store_id');
            $storeForeignKey = collect(Schema::getForeignKeys($table))->first(
                fn (array $key): bool => $key['columns'] === ['store_id'],
            );

            $this->assertNotNull($storeColumn, "{$table}.store_id must exist.");
            $this->assertFalse($storeColumn['nullable'], "{$table}.store_id must be required.");
            $this->assertSame('stores', $storeForeignKey['foreign_table'] ?? null, "{$table}.store_id must reference stores.");
            $this->assertSame('cascade', $storeForeignKey['on_delete'] ?? null, "{$table} must be deleted with its store.");
        }
    }

    public function test_current_store_is_scoped_to_one_request_or_job_lifecycle(): void
    {
        $store = Store::factory()->create();
        $firstContext = app(CurrentStore::class);
        $firstContext->set($store);

        $this->app->forgetScopedInstances();

        $nextContext = app(CurrentStore::class);

        $this->assertNotSame($firstContext, $nextContext);
        $this->assertFalse($nextContext->isSet());
    }

    public function test_super_admin_cannot_route_bind_tenant_data_without_a_tenant_context(): void
    {
        $store = Store::factory()->create();
        app(CurrentStore::class)->set($store);
        $commission = CommissionRequest::factory()->create();

        $superAdmin = User::factory()->create(['is_admin' => true, 'store_id' => null]);
        $superAdmin->roles()->attach(Role::firstOrCreate(['name' => 'super_admin']));

        $this->actingAs($superAdmin)
            ->getJson("/api/v1/commission-requests/{$commission->id}")
            ->assertNotFound();

        $this->assertFalse(app(CurrentStore::class)->isSet());
    }

    public function test_route_binding_uses_the_authenticated_admins_store(): void
    {
        $ownStore = Store::factory()->create();
        $foreignStore = Store::factory()->create();
        $admin = User::factory()->create(['is_admin' => true, 'store_id' => $ownStore->id]);
        $admin->roles()->attach(Role::firstOrCreate(['name' => 'admin']));

        app(CurrentStore::class)->set($ownStore);
        $ownCommission = CommissionRequest::factory()->create();
        app(CurrentStore::class)->set($foreignStore);
        $foreignCommission = CommissionRequest::factory()->create();

        $this->actingAs($admin)
            ->getJson("/api/v1/commission-requests/{$ownCommission->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $ownCommission->id);

        $this->actingAs($admin)
            ->getJson("/api/v1/commission-requests/{$foreignCommission->id}")
            ->assertNotFound();
    }

    public function test_tenant_ownership_cannot_be_reassigned_after_creation(): void
    {
        $ownStore = Store::factory()->create();
        $foreignStore = Store::factory()->create();
        app(CurrentStore::class)->set($ownStore);
        $product = Product::factory()->create();

        try {
            $product->store_id = $foreignStore->id;
            $product->save();
            $this->fail('Changing tenant ownership should have failed.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('tenant ownership cannot be changed', $exception->getMessage());
        }

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'store_id' => $ownStore->id,
        ]);
    }

    public function test_explicit_store_queries_do_not_depend_on_ambient_context(): void
    {
        $ownStore = Store::factory()->create();
        $foreignStore = Store::factory()->create();
        app(CurrentStore::class)->set($ownStore);
        $ownProduct = Product::factory()->create();
        app(CurrentStore::class)->set($foreignStore);
        Product::factory()->create();

        app(CurrentStore::class)->clear();
        $products = Product::forStore($ownStore)->get();

        $this->assertCount(1, $products);
        $this->assertTrue($products->first()->is($ownProduct));
    }
}

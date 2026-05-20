<?php

namespace Tests\Feature\Tenancy;

use App\Models\CommissionRequest;
use App\Models\Discount;
use App\Models\GiftCardDenomination;
use App\Models\Order;
use App\Models\Post;
use App\Models\PostCategory;
use App\Models\Product;
use App\Models\Role;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Store;
use App\Models\Subscriber;
use App\Models\User;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogScopingTest extends TestCase
{
    use RefreshDatabase;

    protected Store $storeA;

    protected Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storeA = Store::firstOrCreate(
            ['slug' => Store::DEFAULT_SLUG],
            ['name' => 'Default Store', 'status' => 'active']
        );

        $this->storeB = Store::factory()->create(['name' => 'Watch World']);
    }

    public function test_product_is_auto_filled_with_current_store_id_on_create(): void
    {
        app(CurrentStore::class)->set($this->storeB);

        $product = Product::factory()->create(['name' => 'Submariner']);

        $this->assertSame($this->storeB->id, $product->store_id);
    }

    public function test_products_are_isolated_by_store(): void
    {
        app(CurrentStore::class)->set($this->storeA);
        Product::factory()->create(['name' => 'A1']);
        Product::factory()->create(['name' => 'A2']);

        app(CurrentStore::class)->set($this->storeB);
        Product::factory()->create(['name' => 'B1']);

        app(CurrentStore::class)->set($this->storeA);
        $this->assertSame(2, Product::count());

        app(CurrentStore::class)->set($this->storeB);
        $this->assertSame(1, Product::count());
        $this->assertSame('B1', Product::first()?->name);
    }

    public function test_orders_are_isolated_by_store(): void
    {
        app(CurrentStore::class)->set($this->storeA);
        Order::factory()->count(3)->create();

        app(CurrentStore::class)->set($this->storeB);
        Order::factory()->count(1)->create();

        $this->assertSame(1, Order::count());

        app(CurrentStore::class)->set($this->storeA);
        $this->assertSame(3, Order::count());
    }

    public function test_discounts_are_isolated_by_store(): void
    {
        app(CurrentStore::class)->set($this->storeA);
        Discount::factory()->create(['code' => 'SUMMER']);

        app(CurrentStore::class)->set($this->storeB);
        Discount::factory()->create(['code' => 'WINTER']);

        $this->assertSame(1, Discount::count());
        $this->assertSame('WINTER', Discount::first()?->code);
    }

    public function test_blog_post_and_category_are_isolated_by_store(): void
    {
        app(CurrentStore::class)->set($this->storeA);
        $catA = PostCategory::create(['name' => 'News A', 'slug' => 'news-a-'.uniqid(), 'sort_order' => 1]);
        Post::create(['title' => 'Post A', 'slug' => 'post-a-'.uniqid(), 'body' => 'a', 'category_id' => $catA->id]);

        app(CurrentStore::class)->set($this->storeB);
        $catB = PostCategory::create(['name' => 'News B', 'slug' => 'news-b-'.uniqid(), 'sort_order' => 1]);
        Post::create(['title' => 'Post B', 'slug' => 'post-b-'.uniqid(), 'body' => 'b', 'category_id' => $catB->id]);

        $this->assertSame(1, Post::count());
        $this->assertSame(1, PostCategory::count());
        $this->assertSame('Post B', Post::first()?->title);
    }

    public function test_services_and_service_categories_are_isolated_by_store(): void
    {
        app(CurrentStore::class)->set($this->storeA);
        $catA = ServiceCategory::create(['name' => 'Repairs', 'slug' => 'repairs-'.uniqid(), 'sort_order' => 1]);
        Service::create(['title' => 'Polish A', 'slug' => 'polish-a-'.uniqid(), 'body' => 'a', 'category_id' => $catA->id]);

        app(CurrentStore::class)->set($this->storeB);
        $catB = ServiceCategory::create(['name' => 'Polish', 'slug' => 'polish-'.uniqid(), 'sort_order' => 1]);
        Service::create(['title' => 'Polish B', 'slug' => 'polish-b-'.uniqid(), 'body' => 'b', 'category_id' => $catB->id]);

        $this->assertSame(1, Service::count());
        $this->assertSame(1, ServiceCategory::count());
        $this->assertSame('Polish B', Service::first()?->title);
    }

    public function test_commission_requests_are_isolated_by_store(): void
    {
        app(CurrentStore::class)->set($this->storeA);
        CommissionRequest::factory()->create(['customer_name' => 'Alice']);

        app(CurrentStore::class)->set($this->storeB);
        CommissionRequest::factory()->create(['customer_name' => 'Bob']);

        $this->assertSame(1, CommissionRequest::count());
        $this->assertSame('Bob', CommissionRequest::first()?->customer_name);
    }

    public function test_subscribers_are_isolated_by_store(): void
    {
        app(CurrentStore::class)->set($this->storeA);
        Subscriber::create(['email' => 'a@example.com', 'source' => 'popup']);

        app(CurrentStore::class)->set($this->storeB);
        Subscriber::create(['email' => 'b@example.com', 'source' => 'popup']);

        $this->assertSame(1, Subscriber::count());
        $this->assertSame('b@example.com', Subscriber::first()?->email);
    }

    public function test_gift_card_denominations_are_isolated_by_store(): void
    {
        app(CurrentStore::class)->set($this->storeA);
        GiftCardDenomination::create(['amount' => 50, 'is_enabled' => true, 'sort_order' => 1]);

        app(CurrentStore::class)->set($this->storeB);
        GiftCardDenomination::create(['amount' => 100, 'is_enabled' => true, 'sort_order' => 1]);

        $this->assertSame(1, GiftCardDenomination::count());
        $this->assertEquals(100, GiftCardDenomination::first()?->amount);
    }

    public function test_super_admin_can_bypass_scope_to_see_all_stores(): void
    {
        app(CurrentStore::class)->set($this->storeA);
        Product::factory()->count(2)->create();

        app(CurrentStore::class)->set($this->storeB);
        Product::factory()->count(3)->create();

        $total = Product::withoutGlobalScope(\App\Models\Scopes\StoreScope::class)->count();

        $this->assertSame(5, $total);
    }

    public function test_admin_api_returns_404_for_cross_store_product_lookup(): void
    {
        // Admin from store A tries to view a product that belongs to store B.
        // The global scope on Product means the admin's auth context (which
        // sets CurrentStore to store A via ResolveAdminStore) filters store
        // B's product out, so route-model binding 404s.

        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $adminA = User::factory()->create(['is_admin' => true, 'store_id' => $this->storeA->id]);
        $adminA->roles()->attach($adminRole);

        app(CurrentStore::class)->set($this->storeB);
        $storeBProduct = Product::factory()->create(['name' => 'Belongs to B']);

        app(CurrentStore::class)->clear();

        $this->actingAs($adminA)
            ->patchJson("/api/v1/products/{$storeBProduct->id}", ['name' => 'Hijacked'])
            ->assertNotFound();

        // And the product is unchanged.
        $stillB = Product::withoutGlobalScope(\App\Models\Scopes\StoreScope::class)
            ->find($storeBProduct->id);
        $this->assertSame('Belongs to B', $stillB?->name);
    }
}

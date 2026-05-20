<?php

namespace Tests\Feature\Tenancy;

use App\Models\Discount;
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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SlugUniquenessTest extends TestCase
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

    public function test_two_stores_can_share_a_product_slug(): void
    {
        app(CurrentStore::class)->set($this->storeA);
        Product::factory()->create(['name' => 'Submariner', 'slug' => 'submariner']);

        app(CurrentStore::class)->set($this->storeB);
        $shared = Product::factory()->create(['name' => 'Submariner', 'slug' => 'submariner']);

        $this->assertSame('submariner', $shared->slug);
        $this->assertSame($this->storeB->id, $shared->store_id);
    }

    public function test_same_product_slug_within_one_store_still_rejected(): void
    {
        app(CurrentStore::class)->set($this->storeA);
        Product::factory()->create(['slug' => 'apollo']);

        $this->expectException(QueryException::class);
        Product::factory()->create(['slug' => 'apollo']);
    }

    public function test_two_stores_can_share_a_post_slug(): void
    {
        app(CurrentStore::class)->set($this->storeA);
        Post::create(['title' => 'Hello', 'slug' => 'hello', 'body' => 'a']);

        app(CurrentStore::class)->set($this->storeB);
        Post::create(['title' => 'Hello', 'slug' => 'hello', 'body' => 'b']);

        $this->assertSame(1, Post::count());
        $this->assertSame(2, Post::withoutGlobalScope(\App\Models\Scopes\StoreScope::class)
            ->where('slug', 'hello')
            ->count());
    }

    public function test_two_stores_can_share_a_service_slug(): void
    {
        app(CurrentStore::class)->set($this->storeA);
        Service::create(['title' => 'Polish', 'slug' => 'polish', 'body' => 'a']);

        app(CurrentStore::class)->set($this->storeB);
        Service::create(['title' => 'Polish', 'slug' => 'polish', 'body' => 'b']);

        $this->assertSame(2, Service::withoutGlobalScope(\App\Models\Scopes\StoreScope::class)
            ->where('slug', 'polish')
            ->count());
    }

    public function test_two_stores_can_share_a_post_category_slug(): void
    {
        app(CurrentStore::class)->set($this->storeA);
        PostCategory::create(['name' => 'News', 'slug' => 'news', 'sort_order' => 1]);

        app(CurrentStore::class)->set($this->storeB);
        PostCategory::create(['name' => 'News', 'slug' => 'news', 'sort_order' => 1]);

        $this->assertSame(2, PostCategory::withoutGlobalScope(\App\Models\Scopes\StoreScope::class)
            ->where('slug', 'news')
            ->count());
    }

    public function test_two_stores_can_share_a_service_category_slug(): void
    {
        app(CurrentStore::class)->set($this->storeA);
        ServiceCategory::create(['name' => 'Repairs', 'slug' => 'repairs', 'sort_order' => 1]);

        app(CurrentStore::class)->set($this->storeB);
        ServiceCategory::create(['name' => 'Repairs', 'slug' => 'repairs', 'sort_order' => 1]);

        $this->assertSame(2, ServiceCategory::withoutGlobalScope(\App\Models\Scopes\StoreScope::class)
            ->where('slug', 'repairs')
            ->count());
    }

    public function test_two_stores_can_share_a_discount_code(): void
    {
        app(CurrentStore::class)->set($this->storeA);
        Discount::factory()->create(['code' => 'SUMMER']);

        app(CurrentStore::class)->set($this->storeB);
        Discount::factory()->create(['code' => 'SUMMER']);

        $this->assertSame(2, Discount::withoutGlobalScope(\App\Models\Scopes\StoreScope::class)
            ->where('code', 'SUMMER')
            ->count());
    }

    public function test_two_stores_can_share_a_subscriber_email(): void
    {
        app(CurrentStore::class)->set($this->storeA);
        Subscriber::create(['email' => 'fan@example.com', 'source' => 'popup']);

        app(CurrentStore::class)->set($this->storeB);
        Subscriber::create(['email' => 'fan@example.com', 'source' => 'popup']);

        $this->assertSame(2, Subscriber::withoutGlobalScope(\App\Models\Scopes\StoreScope::class)
            ->where('email', 'fan@example.com')
            ->count());
    }

    public function test_duplicate_subscriber_within_one_store_still_rejected(): void
    {
        app(CurrentStore::class)->set($this->storeA);
        Subscriber::create(['email' => 'dup@example.com', 'source' => 'popup']);

        $this->expectException(QueryException::class);
        Subscriber::create(['email' => 'dup@example.com', 'source' => 'popup']);
    }

    public function test_discount_validation_allows_same_code_across_stores_via_api(): void
    {
        // Admin in store A creates SUMMER.
        $adminRole = Role::firstOrCreate(['name' => 'admin']);

        $adminA = User::factory()->create(['is_admin' => true, 'store_id' => $this->storeA->id]);
        $adminA->roles()->attach($adminRole);

        $adminB = User::factory()->create(['is_admin' => true, 'store_id' => $this->storeB->id]);
        $adminB->roles()->attach($adminRole);

        $this->actingAs($adminA)
            ->postJson('/api/v1/discounts', [
                'code' => 'SUMMER', 'type' => 'percentage', 'value' => 10,
            ])
            ->assertCreated();

        // Admin in store B can also create SUMMER — the unique rule is scoped.
        $this->actingAs($adminB)
            ->postJson('/api/v1/discounts', [
                'code' => 'SUMMER', 'type' => 'percentage', 'value' => 15,
            ])
            ->assertCreated();

        // But the same admin cannot create SUMMER twice in the same store.
        $this->actingAs($adminA)
            ->postJson('/api/v1/discounts', [
                'code' => 'SUMMER', 'type' => 'percentage', 'value' => 20,
            ])
            ->assertUnprocessable();
    }
}

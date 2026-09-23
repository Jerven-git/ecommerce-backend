<?php

namespace Tests\Feature;

use App\Http\Middleware\SubscriptionMiddleware;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class SubscriptionGatingApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('storefront.base_domain', 'localhost');
        config()->set('storefront.default_store_slug', Store::DEFAULT_SLUG);
    }

    public function test_unsubscribed_store_admin_is_denied_admin_endpoints(): void
    {
        $admin = $this->adminForStore(
            Store::factory()->unsubscribed()->create()
        );

        $this->actingAs($admin)
            ->getJson('/api/v1/dashboard/stats')
            ->assertStatus(402)
            ->assertJsonPath('code', 'subscription_required')
            ->assertJsonPath('subscription_status', Store::SUBSCRIPTION_UNSUBSCRIBED);
    }

    public function test_comped_store_admin_can_access_admin_endpoints(): void
    {
        $admin = $this->adminForStore(
            Store::factory()->create(['subscription_status' => Store::SUBSCRIPTION_COMPED])
        );

        $this->actingAs($admin)
            ->getJson('/api/v1/dashboard/stats')
            ->assertOk();
    }

    public function test_active_store_admin_can_access_admin_endpoints(): void
    {
        $admin = $this->adminForStore(
            Store::factory()->create([
                'subscription_status' => Store::SUBSCRIPTION_ACTIVE,
                'subscription_expires_at' => now()->addMonth(),
            ])
        );

        $this->actingAs($admin)
            ->getJson('/api/v1/dashboard/stats')
            ->assertOk();
    }

    public function test_expired_store_admin_is_denied_admin_endpoints(): void
    {
        $admin = $this->adminForStore(
            Store::factory()->create([
                'subscription_status' => Store::SUBSCRIPTION_ACTIVE,
                'subscription_expires_at' => now()->subDay(),
            ])
        );

        $this->actingAs($admin)
            ->getJson('/api/v1/dashboard/stats')
            ->assertStatus(402);
    }

    public function test_cancelled_store_admin_keeps_access_until_period_end(): void
    {
        $admin = $this->adminForStore(
            Store::factory()->create([
                'subscription_status' => Store::SUBSCRIPTION_CANCELLED,
                'subscription_expires_at' => now()->addWeek(),
            ])
        );

        $this->actingAs($admin)
            ->getJson('/api/v1/dashboard/stats')
            ->assertOk();
    }

    public function test_gating_is_at_store_level_not_user_level(): void
    {
        $store = Store::factory()->unsubscribed()->create();
        $owner = $this->adminForStore($store);
        $staff = $this->adminForStore($store);

        // Any user bound to the gated tenant is denied — not just the owner.
        $this->actingAs($owner)->getJson('/api/v1/dashboard/stats')->assertStatus(402);
        $this->actingAs($staff)->getJson('/api/v1/dashboard/stats')->assertStatus(402);
    }

    public function test_staff_of_comped_store_is_not_gated(): void
    {
        $staff = $this->adminForStore(
            Store::factory()->create(['subscription_status' => Store::SUBSCRIPTION_COMPED])
        );

        $this->actingAs($staff)
            ->getJson('/api/v1/dashboard/stats')
            ->assertOk();
    }

    public function test_unsubscribed_store_owner_can_still_log_in(): void
    {
        $admin = $this->adminForStore(Store::factory()->unsubscribed()->create());

        // /user is intentionally not gated so the SPA can render the
        // subscribe-now state after login.
        $this->actingAs($admin)
            ->getJson('/api/v1/user')
            ->assertOk()
            ->assertJsonPath('user.store.subscription_status', Store::SUBSCRIPTION_UNSUBSCRIBED);
    }

    public function test_super_admin_impersonating_a_gated_store_owner_bypasses(): void
    {
        $store = Store::factory()->unsubscribed()->create();
        $admin = $this->adminForStore($store);

        $superRole = Role::firstOrCreate(['name' => 'super_admin']);
        $super = User::factory()->create(['is_admin' => true, 'store_id' => null]);
        $super->roles()->attach($superRole);

        app('session')->start();
        app('session')->put(config('laravel-impersonate.session_key'), $super->id);

        $middleware = app(SubscriptionMiddleware::class);
        $request = Request::create('/api/v1/dashboard/stats', 'GET');
        $request->setUserResolver(fn () => $admin);
        $request->setLaravelSession(app('session')->driver());

        $response = $middleware->handle($request, fn () => response('ok'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_unsubscribed_storefront_is_hidden_and_comped_storefront_renders(): void
    {
        Store::factory()->unsubscribed()->create(['slug' => 'gate-me']);
        Store::factory()->create([
            'slug' => 'open-me',
            'subscription_status' => Store::SUBSCRIPTION_COMPED,
        ]);

        $this->getJson('http://gate-me.localhost/api/v1/products')->assertStatus(404);
        $this->getJson('http://open-me.localhost/api/v1/products')->assertOk();
    }

    protected function adminForStore(Store $store): User
    {
        $role = Role::firstOrCreate(['name' => 'admin']);
        $admin = User::factory()->create([
            'is_admin' => true,
            'store_id' => $store->id,
        ]);
        $admin->roles()->attach($role);

        return $admin;
    }
}

<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\Activity;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    protected Store $storeA;

    protected Store $storeB;

    protected User $superAdmin;

    protected User $adminA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storeA = Store::firstOrCreate(
            ['slug' => Store::DEFAULT_SLUG],
            ['name' => 'Store A', 'status' => 'active']
        );
        $this->storeB = Store::factory()->create(['name' => 'Store B']);

        $superRole = Role::firstOrCreate(['name' => 'super_admin']);
        $adminRole = Role::firstOrCreate(['name' => 'admin']);

        $this->superAdmin = User::factory()->create(['is_admin' => true, 'store_id' => null]);
        $this->superAdmin->roles()->attach($superRole);

        $this->adminA = User::factory()->create(['is_admin' => true, 'store_id' => $this->storeA->id]);
        $this->adminA->roles()->attach($adminRole);

        app(CurrentStore::class)->set($this->storeA);
        activity('test')->causedBy($this->adminA)->log('store A event');

        app(CurrentStore::class)->set($this->storeB);
        activity('test')->causedBy($this->superAdmin)->log('store B event');

        app(CurrentStore::class)->clear();
    }

    public function test_super_admin_sees_all_stores_activity_when_no_filter(): void
    {
        $response = $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/activity-log')
            ->assertOk();

        $descriptions = collect($response->json('data'))->pluck('description');
        $this->assertContains('store A event', $descriptions);
        $this->assertContains('store B event', $descriptions);
    }

    public function test_super_admin_can_filter_by_store(): void
    {
        $response = $this->actingAs($this->superAdmin)
            ->getJson("/api/v1/activity-log?store_id={$this->storeB->id}")
            ->assertOk();

        $descriptions = collect($response->json('data'))->pluck('description');
        $this->assertNotContains('store A event', $descriptions);
        $this->assertContains('store B event', $descriptions);
    }

    public function test_store_admin_sees_only_their_store_activity(): void
    {
        $response = $this->actingAs($this->adminA)
            ->getJson('/api/v1/activity-log')
            ->assertOk();

        $descriptions = collect($response->json('data'))->pluck('description');
        $this->assertContains('store A event', $descriptions);
        $this->assertNotContains('store B event', $descriptions);
    }

    public function test_activity_log_captures_store_id_from_current_store(): void
    {
        $storeBActivities = Activity::where('description', 'store B event')->get();
        $this->assertCount(1, $storeBActivities);
        $this->assertSame($this->storeB->id, $storeBActivities->first()->store_id);
    }
}

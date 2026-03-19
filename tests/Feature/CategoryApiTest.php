<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryApiTest extends TestCase
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

    public function test_can_list_categories(): void
    {
        Category::create(['name' => 'Electronics']);
        Category::create(['name' => 'Books']);

        $this->getJson('/api/v1/categories')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_admin_can_create_category(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/categories', ['name' => 'Clothing'])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Clothing');
    }

    public function test_unauthenticated_cannot_create_category(): void
    {
        $this->postJson('/api/v1/categories', ['name' => 'Test'])
            ->assertStatus(401);
    }

    public function test_admin_can_update_category(): void
    {
        $cat = Category::create(['name' => 'Old Name']);

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/categories/{$cat->id}", ['name' => 'New Name'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name');
    }

    public function test_cannot_set_category_as_own_parent(): void
    {
        $cat = Category::create(['name' => 'Self']);

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/categories/{$cat->id}", ['parent_id' => $cat->id])
            ->assertStatus(422);
    }

    public function test_admin_can_delete_category(): void
    {
        $cat = Category::create(['name' => 'Delete Me']);

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/categories/{$cat->id}")
            ->assertOk();

        $this->assertDatabaseMissing('categories', ['id' => $cat->id]);
    }

    public function test_xss_stripped_from_category_name(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/categories', ['name' => '<b>Bold</b><script>x</script>'])
            ->assertStatus(201);

        $cat = Category::latest()->first();
        $this->assertStringNotContainsString('<script>', $cat->name);
        $this->assertStringNotContainsString('<b>', $cat->name);
        $this->assertStringContainsString('Bold', $cat->name);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    public function test_admin_can_move_a_category_tree_under_another_category(): void
    {
        $source = Category::create(['name' => 'Source']);
        $child = Category::create(['name' => 'Child', 'parent_id' => $source->id]);
        $grandchild = Category::create(['name' => 'Grandchild', 'parent_id' => $child->id]);
        $target = Category::create(['name' => 'Target']);

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/categories/{$source->id}", [
                'parent_id' => $target->id,
                'sort_order' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('data.parent_id', $target->id);

        $this->assertDatabaseHas('categories', ['id' => $source->id, 'parent_id' => $target->id]);
        $this->assertDatabaseHas('categories', ['id' => $child->id, 'parent_id' => $source->id]);
        $this->assertDatabaseHas('categories', ['id' => $grandchild->id, 'parent_id' => $child->id]);
    }

    public function test_admin_can_reorder_sibling_categories(): void
    {
        $first = Category::create(['name' => 'First', 'sort_order' => 0]);
        $second = Category::create(['name' => 'Second', 'sort_order' => 1]);
        $third = Category::create(['name' => 'Third', 'sort_order' => 2]);

        $this->actingAs($this->admin)
            ->postJson('/api/v1/categories/reorder', [
                'ids' => [$third->id, $first->id, $second->id],
            ])
            ->assertOk();

        $this->assertDatabaseHas('categories', ['id' => $third->id, 'sort_order' => 0]);
        $this->assertDatabaseHas('categories', ['id' => $first->id, 'sort_order' => 1]);
        $this->assertDatabaseHas('categories', ['id' => $second->id, 'sort_order' => 2]);
    }

    public function test_cannot_move_category_under_its_descendant(): void
    {
        $parent = Category::create(['name' => 'Parent']);
        $child = Category::create(['name' => 'Child', 'parent_id' => $parent->id]);

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/categories/{$parent->id}", ['parent_id' => $child->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot set a descendant as the parent.');
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

    public function test_create_category_generates_slug(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/categories', ['name' => 'Garden Tools'])
            ->assertStatus(201)
            ->assertJsonPath('data.slug', 'garden-tools');
    }

    public function test_duplicate_name_gets_unique_slug(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/categories', ['name' => 'Accessories'])->assertStatus(201);
        $this->actingAs($this->admin)->postJson('/api/v1/categories', ['name' => 'Accessories'])->assertStatus(201);

        $slugs = Category::pluck('slug')->all();
        $this->assertContains('accessories', $slugs);
        $this->assertContains('accessories-1', $slugs);
    }

    public function test_admin_can_upload_and_remove_category_cover(): void
    {
        Storage::fake('public');
        $cat = Category::create(['name' => 'Wall Decor']);

        $this->actingAs($this->admin)
            ->post("/api/v1/categories/{$cat->id}", [
                '_method' => 'PATCH',
                'overlay_opacity' => 40,
                'image' => UploadedFile::fake()->image('cover.jpg', 800, 600),
            ])
            ->assertOk()
            ->assertJsonPath('data.overlay_opacity', 40);

        $cat->refresh();
        $this->assertNotNull($cat->image_url);
        $this->assertNotNull($cat->media()->where('collection', 'image')->first());

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/categories/{$cat->id}/image")
            ->assertOk()
            ->assertJsonPath('data.image_url', null);

        $this->assertNull($cat->fresh()->media()->where('collection', 'image')->first());
    }
}

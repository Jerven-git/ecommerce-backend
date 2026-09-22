<?php

namespace Tests\Feature;

use App\Models\PostCategory;
use App\Models\Store;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostCategoryApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $store = Store::firstOrCreate(
            ['slug' => Store::DEFAULT_SLUG],
            ['name' => 'Default Store', 'status' => 'active']
        );
        app(CurrentStore::class)->set($store);
    }

    private function makeCategory(string $name, string $slug): PostCategory
    {
        return PostCategory::create([
            'name' => $name,
            'slug' => $slug,
            'sort_order' => 0,
        ]);
    }

    public function test_index_returns_plain_array_by_default(): void
    {
        $this->makeCategory('New Arrivals', 'new-arrivals');
        $this->makeCategory('Buying Guides', 'buying-guides');

        $this->getJson('/api/v1/post-categories')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissingPath('current_page');
    }

    public function test_index_search_filters_by_name_and_slug(): void
    {
        $this->makeCategory('New Arrivals', 'new-arrivals');
        $this->makeCategory('Buying Guides', 'buying-guides');

        $this->getJson('/api/v1/post-categories?search=guide')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'buying-guides');
    }

    public function test_index_paginates_ten_per_page_by_default(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->makeCategory("Category {$i}", "category-{$i}");
        }

        $response = $this->getJson('/api/v1/post-categories?page=1&per_page=10')
            ->assertOk();

        $response->assertJsonPath('total', 12)
            ->assertJsonPath('per_page', 10)
            ->assertJsonPath('last_page', 2)
            ->assertJsonCount(10, 'data');

        $this->getJson('/api/v1/post-categories?page=2&per_page=10')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_index_search_combines_with_pagination(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->makeCategory("Guide {$i}", "guide-{$i}");
        }
        $this->makeCategory('New Arrivals', 'new-arrivals');

        $this->getJson('/api/v1/post-categories?search=guide&page=1&per_page=10')
            ->assertOk()
            ->assertJsonPath('total', 5)
            ->assertJsonCount(5, 'data');
    }
}

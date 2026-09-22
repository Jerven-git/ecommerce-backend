<?php

namespace Tests\Feature;

use App\Models\ServiceCategory;
use App\Models\Store;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceCategoryApiTest extends TestCase
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

    private function makeCategory(string $name, string $slug): ServiceCategory
    {
        return ServiceCategory::create([
            'name' => $name,
            'slug' => $slug,
            'sort_order' => 0,
        ]);
    }

    public function test_index_returns_plain_array_by_default(): void
    {
        $this->makeCategory('Repairs', 'repairs');
        $this->makeCategory('Polish', 'polish');

        $this->getJson('/api/v1/service-categories')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissingPath('current_page');
    }

    public function test_index_search_filters_by_name_and_slug(): void
    {
        $this->makeCategory('Repairs', 'repairs');
        $this->makeCategory('Polish', 'polish');

        $this->getJson('/api/v1/service-categories?search=pol')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'polish');
    }

    public function test_index_paginates_ten_per_page_by_default(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->makeCategory("Category {$i}", "category-{$i}");
        }

        $response = $this->getJson('/api/v1/service-categories?page=1&per_page=10')
            ->assertOk();

        $response->assertJsonPath('total', 12)
            ->assertJsonPath('per_page', 10)
            ->assertJsonPath('last_page', 2)
            ->assertJsonCount(10, 'data');

        $this->getJson('/api/v1/service-categories?page=2&per_page=10')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_index_search_combines_with_pagination(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->makeCategory("Repair {$i}", "repair-{$i}");
        }
        $this->makeCategory('Polish', 'polish');

        $this->getJson('/api/v1/service-categories?search=repair&page=1&per_page=10')
            ->assertOk()
            ->assertJsonPath('total', 5)
            ->assertJsonCount(5, 'data');
    }
}

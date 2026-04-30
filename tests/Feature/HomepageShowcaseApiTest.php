<?php

namespace Tests\Feature;

use App\Http\Middleware\SessionLifetimeMiddleware;
use App\Jobs\OptimizeShowcaseVideoJob;
use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\SiteConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HomepageShowcaseApiTest extends TestCase
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

    /**
     * Admin routes sit behind `auth:sanctum` + `session.lifetime`. The latter
     * reads `$request->session()`, which is unavailable in JSON test requests
     * driven by `actingAs`, so we disable it for these tests — its only role
     * is enforcing absolute/idle session timeouts in the live SPA, which is
     * orthogonal to the controller behaviour under test here.
     */
    private function asAdmin(): self
    {
        return $this->actingAs($this->admin)
            ->withoutMiddleware(SessionLifetimeMiddleware::class);
    }

    public function test_show_returns_default_showcase_shape_when_unset(): void
    {
        $this->getJson('/api/v1/site-config')
            ->assertOk()
            ->assertJsonPath('data.homepage_showcase.enabled', false)
            ->assertJsonPath('data.homepage_showcase.label', '')
            ->assertJsonPath('data.homepage_showcase.heading', '')
            ->assertJsonPath('data.homepage_showcase.subtitle', '')
            ->assertJsonPath('data.homepage_showcase.video_status', 'idle')
            ->assertJsonCount(4, 'data.homepage_showcase.tiles');
    }

    public function test_admin_can_save_showcase_heading_label_and_subtitle(): void
    {
        // Heading/label/subtitle round-trip independent of visibility — keep
        // enabled=false so the test isn't entangled with the "visible needs a
        // complete tile" rule.
        $payload = [
            'homepage_showcase' => [
                'enabled' => false,
                'label' => 'Featured',
                'heading' => 'Find your product',
                'subtitle' => 'Hand-picked categories to start with.',
                'tiles' => array_fill(0, 4, [
                    'title' => '', 'cta_label' => 'SHOP NOW', 'category_id' => null, 'featured_product_id' => null,
                ]),
            ],
        ];

        $this->asAdmin()
            ->patchJson('/api/v1/site-config', $payload)
            ->assertOk()
            ->assertJsonPath('data.homepage_showcase.label', 'Featured')
            ->assertJsonPath('data.homepage_showcase.heading', 'Find your product')
            ->assertJsonPath('data.homepage_showcase.subtitle', 'Hand-picked categories to start with.');
    }

    public function test_admin_can_save_showcase_config_and_image_url_resolves_from_product(): void
    {
        $category = Category::create(['name' => 'Skincare']);
        $product = Product::create([
            'name' => 'Serum',
            'slug' => 'serum',
            'description' => 'A serum',
            'price' => 9.99,
            'stock' => 5,
            'image_url' => 'https://example.test/serum.jpg',
            'is_active' => true,
            'category_id' => $category->id,
        ]);

        $payload = [
            'homepage_showcase' => [
                'enabled' => true,
                'heading' => 'Find your product',
                'tiles' => [
                    ['title' => 'K-Beauty', 'cta_label' => 'SHOP NOW', 'category_id' => $category->id, 'featured_product_id' => $product->id],
                    ['title' => '', 'cta_label' => 'SHOP NOW', 'category_id' => null, 'featured_product_id' => null],
                    ['title' => '', 'cta_label' => 'SHOP NOW', 'category_id' => null, 'featured_product_id' => null],
                    ['title' => '', 'cta_label' => 'SHOP NOW', 'category_id' => null, 'featured_product_id' => null],
                ],
            ],
        ];

        $this->asAdmin()
            ->patchJson('/api/v1/site-config', $payload)
            ->assertOk()
            ->assertJsonPath('data.homepage_showcase.enabled', true)
            ->assertJsonPath('data.homepage_showcase.tiles.0.title', 'K-Beauty')
            ->assertJsonPath('data.homepage_showcase.tiles.0.category_id', $category->id)
            ->assertJsonPath('data.homepage_showcase.tiles.0.featured_product_id', $product->id)
            ->assertJsonPath('data.homepage_showcase.tiles.0.image_url', 'https://example.test/serum.jpg')
            ->assertJsonPath('data.homepage_showcase.tiles.1.image_url', null);
    }

    public function test_update_rejects_tile_count_other_than_four(): void
    {
        $this->asAdmin()
            ->patchJson('/api/v1/site-config', [
                'homepage_showcase' => [
                    'enabled' => true,
                    'tiles' => [
                        ['title' => 'One', 'cta_label' => 'GO', 'category_id' => null, 'featured_product_id' => null],
                    ],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('homepage_showcase.tiles');
    }

    public function test_update_rejects_enabled_showcase_without_heading(): void
    {
        $category = Category::create(['name' => 'Skincare']);
        $product = Product::create([
            'name' => 'Serum', 'slug' => 'serum', 'description' => '-',
            'price' => 1, 'stock' => 1, 'is_active' => true,
        ]);

        $this->asAdmin()
            ->patchJson('/api/v1/site-config', [
                'homepage_showcase' => [
                    'enabled' => true,
                    'heading' => '',
                    'tiles' => [
                        ['title' => '', 'cta_label' => 'GO', 'category_id' => $category->id, 'featured_product_id' => $product->id],
                        ['title' => '', 'cta_label' => '', 'category_id' => null, 'featured_product_id' => null],
                        ['title' => '', 'cta_label' => '', 'category_id' => null, 'featured_product_id' => null],
                        ['title' => '', 'cta_label' => '', 'category_id' => null, 'featured_product_id' => null],
                    ],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('homepage_showcase.heading');
    }

    public function test_update_rejects_enabled_showcase_with_no_complete_tile(): void
    {
        $this->asAdmin()
            ->patchJson('/api/v1/site-config', [
                'homepage_showcase' => [
                    'enabled' => true,
                    'heading' => 'Find your product',
                    'tiles' => array_fill(0, 4, [
                        'title' => '', 'cta_label' => '', 'category_id' => null, 'featured_product_id' => null,
                    ]),
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('homepage_showcase.tiles');
    }

    public function test_update_rejects_partial_tile_with_title_but_no_category(): void
    {
        $this->asAdmin()
            ->patchJson('/api/v1/site-config', [
                'homepage_showcase' => [
                    'enabled' => false,
                    'tiles' => [
                        ['title' => 'Half-finished', 'cta_label' => 'GO', 'category_id' => null, 'featured_product_id' => null],
                        ['title' => '', 'cta_label' => '', 'category_id' => null, 'featured_product_id' => null],
                        ['title' => '', 'cta_label' => '', 'category_id' => null, 'featured_product_id' => null],
                        ['title' => '', 'cta_label' => '', 'category_id' => null, 'featured_product_id' => null],
                    ],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'homepage_showcase.tiles.0.category_id',
                'homepage_showcase.tiles.0.featured_product_id',
            ]);
    }

    public function test_update_rejects_unknown_category_or_product_id(): void
    {
        $this->asAdmin()
            ->patchJson('/api/v1/site-config', [
                'homepage_showcase' => [
                    'enabled' => true,
                    'tiles' => [
                        ['title' => 'X', 'cta_label' => 'GO', 'category_id' => 99999, 'featured_product_id' => 99999],
                        ['title' => '', 'cta_label' => '', 'category_id' => null, 'featured_product_id' => null],
                        ['title' => '', 'cta_label' => '', 'category_id' => null, 'featured_product_id' => null],
                        ['title' => '', 'cta_label' => '', 'category_id' => null, 'featured_product_id' => null],
                    ],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'homepage_showcase.tiles.0.category_id',
                'homepage_showcase.tiles.0.featured_product_id',
            ]);
    }

    public function test_update_preserves_video_status_set_by_job(): void
    {
        SiteConfig::create(['homepage_showcase' => ['video_status' => 'processing']]);

        // enabled=false here so the test focuses on its real subject —
        // video_status preservation across saves — without tripping the
        // "visible section needs a heading + complete tile" rules.
        $this->asAdmin()
            ->patchJson('/api/v1/site-config', [
                'homepage_showcase' => [
                    'enabled' => false,
                    'tiles' => array_fill(0, 4, [
                        'title' => '', 'cta_label' => '', 'category_id' => null, 'featured_product_id' => null,
                    ]),
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.homepage_showcase.video_status', 'processing');
    }

    public function test_video_upload_requires_admin(): void
    {
        Storage::fake('public');

        $this->postJson('/api/v1/site-config/media/showcase_video', [
            'file' => File::fake()->create('promo.mp4', 1024, 'video/mp4'),
        ])->assertStatus(401);
    }

    public function test_video_upload_stores_file_and_dispatches_optimize_job(): void
    {
        Storage::fake('public');
        Bus::fake();

        $this->asAdmin()
            ->postJson('/api/v1/site-config/media/showcase_video', [
                'file' => File::fake()->create('promo.mp4', 1024, 'video/mp4'),
            ])
            ->assertOk()
            ->assertJsonPath('collection', 'showcase_video');

        Bus::assertDispatched(OptimizeShowcaseVideoJob::class);

        $this->assertSame('processing', SiteConfig::first()->homepage_showcase['video_status']);
    }

    public function test_video_upload_rejects_oversized_file(): void
    {
        Storage::fake('public');

        // 26MB > 25MB ceiling
        $this->asAdmin()
            ->postJson('/api/v1/site-config/media/showcase_video', [
                'file' => File::fake()->create('huge.mp4', 26 * 1024, 'video/mp4'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_video_delete_clears_video_and_resets_status(): void
    {
        Storage::fake('public');
        Bus::fake();

        $this->asAdmin()->postJson('/api/v1/site-config/media/showcase_video', [
            'file' => File::fake()->create('promo.mp4', 1024, 'video/mp4'),
        ])->assertOk();

        $this->asAdmin()
            ->deleteJson('/api/v1/site-config/media/showcase_video')
            ->assertNoContent();

        $this->assertSame('idle', SiteConfig::first()->homepage_showcase['video_status']);
    }
}

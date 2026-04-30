<?php

namespace Tests\Feature;

use App\Http\Middleware\SessionLifetimeMiddleware;
use App\Jobs\OptimizeWatchShopMediaJob;
use App\Models\Media;
use App\Models\Product;
use App\Models\Role;
use App\Models\SiteConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HomepageWatchShopApiTest extends TestCase
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

    private function asAdmin(): self
    {
        return $this->actingAs($this->admin)
            ->withoutMiddleware(SessionLifetimeMiddleware::class);
    }

    private function makeProduct(string $name = 'Test Product'): Product
    {
        $slug = Product::generateUniqueSlug($name);

        return Product::create([
            'name' => $name,
            'slug' => $slug,
            'description' => 'A test product',
            'price' => 9.99,
            'stock' => 5,
            'image_url' => 'https://example.test/'.$slug.'.jpg',
            'is_active' => true,
        ]);
    }

    public function test_show_returns_default_watch_shop_shape(): void
    {
        $this->getJson('/api/v1/site-config')
            ->assertOk()
            ->assertJsonPath('data.homepage_watch_shop.enabled', false)
            ->assertJsonPath('data.homepage_watch_shop.heading', '')
            ->assertJsonPath('data.homepage_watch_shop.cards', []);
    }

    public function test_admin_can_save_heading_and_cards_round_trip(): void
    {
        $product = Product::create([
            'name' => 'Mighty Bamboo Cream',
            'slug' => 'mighty-bamboo-cream',
            'description' => 'Soothing cream',
            'price' => 19.99,
            'stock' => 10,
            'image_url' => 'https://example.test/cream.jpg',
            'is_active' => true,
        ]);

        // Pre-create a media row so the cards.*.media_id validation passes.
        $config = SiteConfig::create([]);
        $media = $config->media()->create([
            'hash' => str_repeat('a', 32),
            'path' => 'site-config/dummy.mp4',
            'format' => 'mp4',
            'mime_type' => 'video/mp4',
            'size' => 1024,
            'collection' => 'watch_shop_card',
        ]);

        $this->asAdmin()
            ->patchJson('/api/v1/site-config', [
                'homepage_watch_shop' => [
                    'enabled' => true,
                    'label' => 'Watch & Shop',
                    'heading' => 'See it in action',
                    'subtitle' => 'Real customers, real reviews.',
                    'cards' => [
                        ['id' => 'card-1', 'media_id' => $media->id, 'product_id' => $product->id],
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.homepage_watch_shop.enabled', true)
            ->assertJsonPath('data.homepage_watch_shop.heading', 'See it in action')
            ->assertJsonPath('data.homepage_watch_shop.cards.0.id', 'card-1')
            ->assertJsonPath('data.homepage_watch_shop.cards.0.media_kind', 'video')
            ->assertJsonPath('data.homepage_watch_shop.cards.0.product.id', $product->id)
            ->assertJsonPath('data.homepage_watch_shop.cards.0.product.slug', 'mighty-bamboo-cream');
    }

    public function test_update_rejects_enabled_watch_shop_without_heading(): void
    {
        $product = $this->makeProduct();

        $this->asAdmin()
            ->patchJson('/api/v1/site-config', [
                'homepage_watch_shop' => [
                    'enabled' => true,
                    'heading' => '',
                    'cards' => [
                        ['id' => 'card-1', 'media_id' => null, 'product_id' => $product->id],
                    ],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('homepage_watch_shop.heading');
    }

    public function test_update_rejects_enabled_watch_shop_with_no_cards(): void
    {
        $this->asAdmin()
            ->patchJson('/api/v1/site-config', [
                'homepage_watch_shop' => [
                    'enabled' => true,
                    'heading' => 'Watch & Shop',
                    'cards' => [],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('homepage_watch_shop.cards');
    }

    public function test_update_rejects_card_without_product(): void
    {
        $this->asAdmin()
            ->patchJson('/api/v1/site-config', [
                'homepage_watch_shop' => [
                    'enabled' => false,
                    'cards' => [
                        ['id' => 'card-1', 'media_id' => null, 'product_id' => null],
                    ],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('homepage_watch_shop.cards.0.product_id');
    }

    public function test_update_rejects_more_than_twelve_cards(): void
    {
        $cards = array_map(
            fn ($i) => ['id' => "card-$i", 'media_id' => null, 'product_id' => null],
            range(1, 13),
        );

        $this->asAdmin()
            ->patchJson('/api/v1/site-config', [
                'homepage_watch_shop' => ['enabled' => true, 'cards' => $cards],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('homepage_watch_shop.cards');
    }

    public function test_video_upload_creates_media_dispatches_optimize_job_and_marks_processing(): void
    {
        Storage::fake('public');
        Bus::fake();

        $response = $this->asAdmin()
            ->postJson('/api/v1/site-config/watch-shop/cards', [
                'file' => File::fake()->create('clip.mp4', 1024, 'video/mp4'),
            ])
            ->assertOk()
            ->assertJsonStructure(['card_id', 'media_id', 'media_url', 'media_kind', 'media_status'])
            ->assertJsonPath('media_kind', 'video')
            ->assertJsonPath('media_status', 'processing');

        Bus::assertDispatched(OptimizeWatchShopMediaJob::class);

        $this->assertSame(1, Media::where('collection', 'watch_shop_card')->count());
        $this->assertNotEmpty($response->json('card_id'));
    }

    public function test_gif_upload_dispatches_optimize_job_for_mp4_conversion(): void
    {
        Storage::fake('public');
        Bus::fake();

        $this->asAdmin()
            ->postJson('/api/v1/site-config/watch-shop/cards', [
                'file' => File::fake()->create('promo.gif', 512, 'image/gif'),
            ])
            ->assertOk()
            ->assertJsonPath('media_status', 'processing');

        Bus::assertDispatched(OptimizeWatchShopMediaJob::class);
    }

    public function test_image_upload_skips_optimize_job_and_is_immediately_ready(): void
    {
        Storage::fake('public');
        Bus::fake();

        $this->asAdmin()
            ->postJson('/api/v1/site-config/watch-shop/cards', [
                'file' => File::fake()->image('photo.jpg', 600, 800),
            ])
            ->assertOk()
            ->assertJsonPath('media_kind', 'image')
            ->assertJsonPath('media_status', 'ready');

        Bus::assertNotDispatched(OptimizeWatchShopMediaJob::class);
    }

    public function test_card_upload_rejects_unsupported_mime(): void
    {
        Storage::fake('public');

        $this->asAdmin()
            ->postJson('/api/v1/site-config/watch-shop/cards', [
                'file' => File::fake()->create('readme.pdf', 50, 'application/pdf'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_card_upload_rejects_oversized_file(): void
    {
        Storage::fake('public');

        // 26MB > 25MB ceiling
        $this->asAdmin()
            ->postJson('/api/v1/site-config/watch-shop/cards', [
                'file' => File::fake()->create('huge.mp4', 26 * 1024, 'video/mp4'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_delete_card_endpoint_removes_media_and_drops_from_json(): void
    {
        Storage::fake('public');
        Bus::fake();

        $product = $this->makeProduct();

        $upload = $this->asAdmin()->postJson('/api/v1/site-config/watch-shop/cards', [
            'file' => File::fake()->create('clip.mp4', 1024, 'video/mp4'),
        ])->assertOk();

        $cardId = $upload->json('card_id');
        $mediaId = $upload->json('media_id');

        // Save the card into homepage_watch_shop so the controller has something to drop.
        $this->asAdmin()->patchJson('/api/v1/site-config', [
            'homepage_watch_shop' => [
                'enabled' => true,
                'heading' => 'Watch & Shop',
                'cards' => [['id' => $cardId, 'media_id' => $mediaId, 'product_id' => $product->id]],
            ],
        ])->assertOk();

        $this->asAdmin()
            ->deleteJson("/api/v1/site-config/watch-shop/cards/{$cardId}")
            ->assertNoContent();

        $this->assertNull(Media::find($mediaId));
        $this->assertEmpty(SiteConfig::first()->homepage_watch_shop['cards'] ?? []);
    }

    public function test_saving_with_card_removed_purges_orphan_media(): void
    {
        Storage::fake('public');
        Bus::fake();

        $product = $this->makeProduct();

        $upload = $this->asAdmin()->postJson('/api/v1/site-config/watch-shop/cards', [
            'file' => File::fake()->create('clip.mp4', 1024, 'video/mp4'),
        ])->assertOk();

        $cardId = $upload->json('card_id');
        $mediaId = $upload->json('media_id');

        $this->asAdmin()->patchJson('/api/v1/site-config', [
            'homepage_watch_shop' => [
                'enabled' => true,
                'heading' => 'Watch & Shop',
                'cards' => [['id' => $cardId, 'media_id' => $mediaId, 'product_id' => $product->id]],
            ],
        ])->assertOk();

        // Re-save with the card removed — section now has no cards, so it has
        // to be hidden too (otherwise the "needs at least one card" rule fires).
        $this->asAdmin()->patchJson('/api/v1/site-config', [
            'homepage_watch_shop' => ['enabled' => false, 'cards' => []],
        ])->assertOk();

        $this->assertNull(Media::find($mediaId));
    }

    public function test_card_media_status_is_resolved_from_media_row(): void
    {
        // Status lives on the media row itself — saving the homepage_watch_shop
        // block from the admin form must not clobber a 'processing' video that
        // the optimize job hasn't finished yet.
        $config = SiteConfig::create([]);
        $media = $config->media()->create([
            'hash' => str_repeat('b', 32),
            'path' => 'site-config/clip.mp4',
            'format' => 'mp4',
            'mime_type' => 'video/mp4',
            'size' => 1024,
            'collection' => 'watch_shop_card',
            'processing_status' => 'processing',
        ]);

        $product = $this->makeProduct();

        $this->asAdmin()->patchJson('/api/v1/site-config', [
            'homepage_watch_shop' => [
                'enabled' => true,
                'heading' => 'Watch & Shop',
                'cards' => [['id' => 'card-1', 'media_id' => $media->id, 'product_id' => $product->id]],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.homepage_watch_shop.cards.0.media_status', 'processing');
    }

    public function test_video_upload_marks_media_processing_immediately(): void
    {
        Storage::fake('public');
        Bus::fake();

        $upload = $this->asAdmin()->postJson('/api/v1/site-config/watch-shop/cards', [
            'file' => File::fake()->create('clip.mp4', 1024, 'video/mp4'),
        ])->assertOk();

        $media = Media::find($upload->json('media_id'));
        $this->assertSame('processing', $media->processing_status);
    }

    public function test_image_upload_marks_media_ready_immediately(): void
    {
        Storage::fake('public');
        Bus::fake();

        $upload = $this->asAdmin()->postJson('/api/v1/site-config/watch-shop/cards', [
            'file' => File::fake()->image('photo.jpg', 600, 800),
        ])->assertOk();

        $media = Media::find($upload->json('media_id'));
        $this->assertSame('ready', $media->processing_status);
    }
}

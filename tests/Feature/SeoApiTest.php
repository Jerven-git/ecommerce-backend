<?php

namespace Tests\Feature;

use App\Http\Middleware\SessionLifetimeMiddleware;
use App\Models\Media;
use App\Models\Post;
use App\Models\Product;
use App\Models\Role;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoApiTest extends TestCase
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

    public function test_product_seo_fields_round_trip(): void
    {
        $response = $this->asAdmin()->postJson('/api/v1/products', [
            'name' => 'SEO Watch',
            'price' => 199.0,
            'seo_title' => 'Premium SEO Watch — Buy Now',
            'seo_description' => 'A finely crafted timepiece with great metadata.',
            'og_image_url' => 'https://example.test/og/watch.jpg',
            'noindex' => true,
        ])->assertCreated();

        $id = $response->json('data.id');

        $product = Product::findOrFail($id);
        $this->assertSame('Premium SEO Watch — Buy Now', $product->seo_title);
        $this->assertSame('A finely crafted timepiece with great metadata.', $product->seo_description);
        $this->assertSame('https://example.test/og/watch.jpg', $product->og_image_url);
        $this->assertTrue($product->noindex);

        // Update flips noindex off and clears og image
        $this->asAdmin()->patchJson("/api/v1/products/{$id}", [
            'noindex' => false,
            'og_image_url' => null,
        ])->assertOk();

        $product->refresh();
        $this->assertFalse($product->noindex);
        $this->assertNull($product->og_image_url);
    }

    public function test_post_seo_fields_round_trip(): void
    {
        $response = $this->asAdmin()->postJson('/api/v1/admin/posts', [
            'title' => 'SEO Friendly Post',
            'seo_title' => 'Custom Post Title for SEO',
            'seo_description' => 'A short snippet that search engines will display.',
            'og_image_url' => 'https://example.test/og/post.jpg',
            'noindex' => true,
            'cover_alt_text' => 'Open notebook beside a vintage watch movement',
        ])->assertCreated();

        $id = $response->json('data.id');

        $post = Post::findOrFail($id);
        $this->assertSame('Custom Post Title for SEO', $post->seo_title);
        $this->assertSame('https://example.test/og/post.jpg', $post->og_image_url);
        $this->assertTrue($post->noindex);
        $this->assertSame('Open notebook beside a vintage watch movement', $post->cover_alt_text);
    }

    public function test_service_seo_fields_round_trip(): void
    {
        $response = $this->asAdmin()->postJson('/api/v1/admin/services', [
            'title' => 'Watch Repair',
            'seo_title' => 'Expert Watch Repair Services',
            'seo_description' => 'Professional watch servicing and repair.',
            'og_image_url' => 'https://example.test/og/service.jpg',
            'noindex' => false,
            'cover_alt_text' => 'Watchmaker repairing a movement under a loupe',
        ])->assertCreated();

        $id = $response->json('data.id');

        $service = Service::findOrFail($id);
        $this->assertSame('Expert Watch Repair Services', $service->seo_title);
        $this->assertSame('https://example.test/og/service.jpg', $service->og_image_url);
        $this->assertFalse($service->noindex);
        $this->assertSame('Watchmaker repairing a movement under a loupe', $service->cover_alt_text);
    }

    public function test_site_config_seo_defaults_and_pages_seo_round_trip(): void
    {
        $this->asAdmin()->patchJson('/api/v1/site-config', [
            'default_seo_title' => 'Shop System United',
            'default_seo_description' => 'Global default description.',
            'default_og_image_url' => 'https://example.test/og/default.jpg',
            'pages_seo' => [
                'about' => [
                    'seo_title' => 'About Us',
                    'seo_description' => 'Our story.',
                    'og_image_url' => 'https://example.test/og/about.jpg',
                    'noindex' => false,
                ],
                'contact' => [
                    'seo_title' => 'Get in Touch',
                    'noindex' => true,
                ],
            ],
        ])->assertOk();

        $response = $this->getJson('/api/v1/site-config')->assertOk();

        $response->assertJsonPath('data.default_seo_title', 'Shop System United');
        $response->assertJsonPath('data.default_seo_description', 'Global default description.');
        $response->assertJsonPath('data.default_og_image_url', 'https://example.test/og/default.jpg');
        $response->assertJsonPath('data.pages_seo.about.seo_title', 'About Us');
        $response->assertJsonPath('data.pages_seo.about.og_image_url', 'https://example.test/og/about.jpg');
        $response->assertJsonPath('data.pages_seo.contact.noindex', true);
    }

    public function test_site_config_rejects_invalid_og_image_url(): void
    {
        $this->asAdmin()->patchJson('/api/v1/site-config', [
            'default_og_image_url' => 'not-a-url',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('default_og_image_url');
    }

    public function test_site_config_canonical_and_logo_alt_round_trip(): void
    {
        $this->asAdmin()->patchJson('/api/v1/site-config', [
            'canonical_base_url' => 'https://shopsystemunited.com',
            'logo_alt_text' => 'Shop System United',
        ])->assertOk();

        $response = $this->getJson('/api/v1/site-config')->assertOk();

        $response->assertJsonPath('data.canonical_base_url', 'https://shopsystemunited.com');
        $response->assertJsonPath('data.logo_alt_text', 'Shop System United');
    }

    public function test_site_config_pages_seo_cover_alt_text_round_trip_for_all_pages(): void
    {
        $alts = [
            'home' => 'Vintage chronograph watch on dark leather',
            'about' => 'Storefront and team at the workshop',
            'contact' => 'Studio entrance with neon sign',
            'blog' => 'Open notebook beside disassembled watch movement',
            'services' => 'Watchmaker repairing a movement under a loupe',
        ];

        $this->asAdmin()->patchJson('/api/v1/site-config', [
            'pages_seo' => array_map(
                fn (string $alt) => ['cover_alt_text' => $alt],
                $alts,
            ),
        ])->assertOk();

        $response = $this->getJson('/api/v1/site-config')->assertOk();

        foreach ($alts as $slug => $alt) {
            $response->assertJsonPath("data.pages_seo.$slug.cover_alt_text", $alt);
        }
    }

    public function test_site_config_rejects_invalid_canonical_base_url(): void
    {
        $this->asAdmin()->patchJson('/api/v1/site-config', [
            'canonical_base_url' => 'not-a-url',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('canonical_base_url');
    }

    public function test_media_alt_text_can_be_updated(): void
    {
        $product = Product::create([
            'name' => 'Carrier',
            'slug' => Product::generateUniqueSlug('Carrier'),
            'price' => 1.0,
            'is_active' => true,
        ]);

        $media = new Media([
            'hash' => str_repeat('a', 32),
            'path' => 'products/test.jpg',
            'format' => 'jpg',
            'mime_type' => 'image/jpeg',
            'size' => 1234,
            'collection' => 'image',
        ]);
        $product->media()->save($media);

        $this->asAdmin()->patchJson("/api/v1/media/{$media->id}", [
            'alt_text' => 'A close-up of a black leather watch strap',
        ])->assertOk();

        $media->refresh();
        $this->assertSame('A close-up of a black leather watch strap', $media->alt_text);

        // Clearing alt_text is supported
        $this->asAdmin()->patchJson("/api/v1/media/{$media->id}", [
            'alt_text' => null,
        ])->assertOk();

        $media->refresh();
        $this->assertNull($media->alt_text);
    }

    public function test_media_alt_text_requires_admin(): void
    {
        $product = Product::create([
            'name' => 'Carrier',
            'slug' => Product::generateUniqueSlug('Carrier-2'),
            'price' => 1.0,
            'is_active' => true,
        ]);

        $media = new Media([
            'hash' => str_repeat('b', 32),
            'path' => 'products/test2.jpg',
            'format' => 'jpg',
            'mime_type' => 'image/jpeg',
            'size' => 1234,
            'collection' => 'image',
        ]);
        $product->media()->save($media);

        $this->patchJson("/api/v1/media/{$media->id}", [
            'alt_text' => 'should not save',
        ])->assertStatus(401);
    }
}

<?php

namespace Tests\Feature\Contracts;

use App\Http\Middleware\SessionLifetimeMiddleware;
use App\Mail\ContactFormMail;
use App\Models\Discount;
use App\Models\PostCategory;
use App\Models\Role;
use App\Models\ServiceCategory;
use App\Models\SiteConfig;
use App\Models\Store;
use App\Models\User;
use App\Modules\Engagement\Application\ContactDelivery;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EditorialEngagementContractTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->admin->roles()->attach(Role::firstOrCreate(['name' => 'admin']));
        $this->withoutMiddleware(SessionLifetimeMiddleware::class);
    }

    public function test_post_admin_and_storefront_responses_keep_their_contracts(): void
    {
        $category = PostCategory::create(['name' => 'Guides', 'slug' => 'guides']);

        $post = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/posts', [
                'title' => 'Choosing a Watch',
                'excerpt' => 'A practical guide.',
                'category_id' => $category->id,
                'is_published' => true,
                'is_featured' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('message', 'Post created successfully')
            ->assertJsonPath('data.slug', 'choosing-a-watch')
            ->assertJsonPath('data.category.id', $category->id)
            ->json('data');

        $this->getJson('/api/v1/posts?limit=3&featured=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $post['id']);

        $this->getJson('/api/v1/posts/'.$post['slug'])
            ->assertOk()
            ->assertJsonPath('data.id', $post['id'])
            ->assertJsonCount(0, 'related');

        $this->actingAs($this->admin)->getJson('/api/v1/admin/posts')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $post['id']);
    }

    public function test_service_admin_and_storefront_responses_keep_their_contracts(): void
    {
        $category = ServiceCategory::create(['name' => 'Repairs', 'slug' => 'repairs']);

        $service = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/services', [
                'title' => 'Watch Restoration',
                'description' => 'Careful restoration service.',
                'category_id' => $category->id,
                'is_published' => true,
                'sort_order' => 2,
            ])
            ->assertCreated()
            ->assertJsonPath('message', 'Service created successfully')
            ->assertJsonPath('data.slug', 'watch-restoration')
            ->json('data');

        $this->getJson('/api/v1/services?limit=3')
            ->assertOk()
            ->assertJsonPath('data.0.id', $service['id']);

        $this->getJson('/api/v1/services/'.$service['slug'])
            ->assertOk()
            ->assertJsonPath('data.id', $service['id'])
            ->assertJsonCount(0, 'related');
    }

    public function test_editorial_category_references_cannot_cross_store_boundaries(): void
    {
        $otherStore = Store::factory()->create();
        app(CurrentStore::class)->set($otherStore);
        $foreignPostCategory = PostCategory::create(['name' => 'Foreign', 'slug' => 'foreign']);
        $foreignServiceCategory = ServiceCategory::create(['name' => 'Foreign', 'slug' => 'foreign']);
        app(CurrentStore::class)->clear();

        $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/posts', [
                'title' => 'Invalid Post',
                'category_id' => $foreignPostCategory->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category_id');

        $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/services', [
                'title' => 'Invalid Service',
                'category_id' => $foreignServiceCategory->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category_id');

        $this->actingAs($this->admin)
            ->postJson('/api/v1/post-categories/reorder', ['ids' => [$foreignPostCategory->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ids.0');

        $this->actingAs($this->admin)
            ->postJson('/api/v1/service-categories/reorder', ['ids' => [$foreignServiceCategory->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ids.0');
    }

    public function test_newsletter_signup_keeps_success_and_idempotent_responses(): void
    {
        $this->postJson('/api/v1/subscribe', [
            'email' => 'Reader@Example.com',
            'source' => 'newsletter',
        ])
            ->assertCreated()
            ->assertExactJson(['message' => 'Subscribed successfully!', 'discount_code' => null]);

        $this->postJson('/api/v1/subscribe', [
            'email' => 'reader@example.com',
            'source' => 'newsletter',
        ])
            ->assertOk()
            ->assertExactJson(['message' => "You're already subscribed!", 'discount_code' => null]);
    }

    public function test_welcome_signup_keeps_the_configured_discount_contract(): void
    {
        $template = Discount::factory()->create([
            'code' => 'WELCOME-TEMPLATE',
            'type' => 'percentage',
            'value' => 10,
            'is_active' => true,
        ]);
        SiteConfig::query()->updateOrCreate([], ['welcome_popup_discount_id' => $template->id]);

        $code = $this->postJson('/api/v1/subscribe', [
            'email' => 'welcome@example.com',
            'source' => 'welcome_popup',
        ])
            ->assertCreated()
            ->assertJsonPath('message', 'Subscribed successfully!')
            ->json('discount_code');

        $this->assertIsString($code);
        $this->assertStringStartsWith('WELCOME-', $code);
        $this->assertDatabaseHas('discounts', ['code' => $code, 'max_uses' => 1]);
    }

    public function test_contact_delivery_keeps_configured_recipient_contract(): void
    {
        Mail::fake();
        SiteConfig::query()->updateOrCreate([], [
            'contact_email' => 'studio@example.com',
            'contact_entries' => [['label' => 'Sales', 'email' => 'sales@example.com']],
        ]);

        app(ContactDelivery::class)->send([
            'name' => 'Reader',
            'email' => 'reader@example.com',
            'subject' => 'Question',
            'message' => 'Can you help?',
            'recaptcha_token' => 'test-token',
        ]);

        Mail::assertSent(ContactFormMail::class, fn ($mail) => $mail->hasTo('sales@example.com'));
    }
}

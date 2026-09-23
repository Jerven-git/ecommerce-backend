<?php

namespace Tests\Feature;

use App\Models\Scopes\StoreScope;
use App\Models\SiteConfig;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_prospect_can_register_an_account_and_store(): void
    {
        $response = $this->postJson('/api/v1/register', [
            'name' => 'Jane Founder',
            'email' => 'jane@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'store_name' => 'Jane & Co',
        ])->assertCreated()->assertJsonPath('redirect', '/subscribe');

        $user = User::where('email', 'jane@example.com')->firstOrFail();
        $store = Store::where('slug', 'jane-co')->firstOrFail();

        $this->assertSame('Jane Founder', $user->name);
        $this->assertSame('admin', $user->roleNames()[0]);
        $this->assertTrue($user->is_admin);
        $this->assertSame($store->id, $user->store_id);
        $this->assertTrue($user->password !== 'secret123');

        // Auto-generated slug, active store, but gated (unsubscribed).
        $this->assertSame('active', $store->status);
        $this->assertSame(Store::SUBSCRIPTION_UNSUBSCRIBED, $store->fresh()->subscription_status);
        $this->assertTrue($store->isSubscriptionGated());

        // Fresh default SiteConfig provisioned for the store.
        $config = SiteConfig::withoutGlobalScope(StoreScope::class)
            ->where('store_id', $store->id)
            ->firstOrFail();
        $this->assertSame('My Store', $config->site_name);

        // Response exposes the gated state so the UI can route to /subscribe.
        $response->assertJsonPath('data.store.subscription_status', Store::SUBSCRIPTION_UNSUBSCRIBED);
        $response->assertJsonPath('data.store.slug', 'jane-co');
    }

    public function test_email_is_lowercased_and_trimmed(): void
    {
        $this->postJson('/api/v1/register', [
            'name' => 'Bob',
            'email' => '  Bob@Example.COM ',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'store_name' => "Bob's Shop",
        ])->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'bob@example.com']);
    }

    public function test_duplicate_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/v1/register', [
            'name' => 'Bob',
            'email' => 'taken@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'store_name' => "Bob's Shop",
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        $this->assertSame(1, User::where('email', 'taken@example.com')->count());
    }

    public function test_duplicate_store_slug_is_rejected(): void
    {
        Store::factory()->create(['slug' => 'bobs-shop']);

        $this->postJson('/api/v1/register', [
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'store_name' => "Bob's Shop",
            'store_slug' => 'bobs-shop',
        ])->assertStatus(422)->assertJsonValidationErrors('store_slug');

        $this->assertNull(User::where('email', 'bob@example.com')->first());
    }

    public function test_invalid_store_slug_format_is_rejected(): void
    {
        $this->postJson('/api/v1/register', [
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'store_name' => "Bob's Shop",
            'store_slug' => 'Bob Shop!',
        ])->assertStatus(422)->assertJsonValidationErrors('store_slug');
    }

    public function test_password_confirmation_mismatch_is_rejected(): void
    {
        $this->postJson('/api/v1/register', [
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'different99',
            'store_name' => "Bob's Shop",
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertSame(0, User::count());
    }

    public function test_requested_slug_is_used_when_provided(): void
    {
        $this->postJson('/api/v1/register', [
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'store_name' => "Bob's Shop",
            'store_slug' => 'custom-name',
        ])->assertCreated();

        $this->assertDatabaseHas('stores', ['slug' => 'custom-name']);
    }

    public function test_slug_suffixes_are_auto_appended_on_collision(): void
    {
        Store::factory()->create(['slug' => 'bobs-shop']);

        $this->postJson('/api/v1/register', [
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'store_name' => "Bob's Shop",
        ])->assertCreated();

        $this->assertDatabaseHas('stores', ['slug' => 'bobs-shop-2']);
    }

    public function test_registration_does_not_auto_log_in(): void
    {
        $this->postJson('/api/v1/register', [
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'store_name' => "Bob's Shop",
        ])->assertCreated()
            ->assertJsonMissingPath('user.roles');
    }

    public function test_registration_is_throttled_per_ip(): void
    {
        foreach (['a@example.com', 'b@example.com', 'c@example.com'] as $email) {
            $this->postJson('/api/v1/register', [
                'name' => 'Spam',
                'email' => $email,
                'password' => 'secret123',
                'password_confirmation' => 'secret123',
                'store_name' => 'Spam Shop',
            ])->assertCreated();
        }

        // 4th attempt within the same minute is blocked.
        $this->postJson('/api/v1/register', [
            'name' => 'Spam',
            'email' => 'd@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'store_name' => 'Spam Shop',
        ])->assertStatus(429);
    }
}

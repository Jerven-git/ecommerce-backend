<?php

namespace Tests\Feature;

use App\Http\Middleware\SessionLifetimeMiddleware;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EditableContentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_optional_page_content_can_be_saved_blank(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::create(['name' => 'admin']));

        $this->actingAs($admin)
            ->withoutMiddleware(SessionLifetimeMiddleware::class)
            ->patchJson('/api/v1/site-config', [
                'contact_entries' => [
                    ['label' => '', 'email' => 'hello@example.com', 'phone' => ''],
                ],
                'homepage_steps' => [
                    'items' => [['title' => '', 'description' => 'Description only']],
                ],
                'homepage_features' => [
                    'items' => [['title' => 'Title only', 'description' => '']],
                ],
                'homepage_stats' => [
                    'items' => [['value' => '', 'label' => 'Customers']],
                ],
                'about_highlights' => [
                    'items' => [['title' => '', 'description' => 'Description only']],
                ],
                'contact_page' => [
                    'promises' => [['title' => '', 'description' => 'Description only']],
                    'faqs' => [['question' => 'Question only', 'answer' => '']],
                ],
                'services_page' => [
                    'summary' => ['items' => [['title' => '', 'description' => 'Description only']]],
                    'stats' => ['items' => [['value' => '24/7', 'label' => '']]],
                ],
            ])
            ->assertOk();
    }
}

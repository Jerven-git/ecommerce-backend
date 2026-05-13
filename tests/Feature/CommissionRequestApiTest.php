<?php

namespace Tests\Feature;

use App\Http\Middleware\SessionLifetimeMiddleware;
use App\Mail\CommissionRequestSubmittedMail;
use App\Models\CommissionRequest;
use App\Models\Role;
use App\Models\SiteConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CommissionRequestApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $role = Role::create(['name' => 'admin']);
        $this->admin->roles()->attach($role);

        SiteConfig::query()->create([
            'contact_email' => 'studio@example.com',
            'modules_enabled' => ['commissions' => true],
        ]);
    }

    private function asAdmin(): self
    {
        return $this->actingAs($this->admin)
            ->withoutMiddleware(SessionLifetimeMiddleware::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_replace([
            'customer_name' => 'Jane Doe',
            'customer_email' => 'jane@example.com',
            'customer_phone' => '+61400000000',
            'title' => 'Mountain landscape',
            'description' => 'Looking for an A2 oil painting of the Blue Mountains at sunrise.',
            'budget_range' => '$500–$1000',
            'preferred_medium' => 'Oil on canvas',
            'preferred_size' => 'A2',
        ], $overrides);
    }

    public function test_anyone_can_submit_a_commission_request(): void
    {
        Mail::fake();

        // Skip the DNS check for the email rule in tests.
        $this->postJson('/api/v1/commission-requests', $this->validPayload([
            'customer_email' => 'jane@example.com',
        ]));

        $this->assertDatabaseHas('commission_requests', [
            'customer_email' => 'jane@example.com',
            'title' => 'Mountain landscape',
            'status' => 'pending',
        ]);

        Mail::assertSent(CommissionRequestSubmittedMail::class);
    }

    public function test_submission_validates_required_fields(): void
    {
        $this->postJson('/api/v1/commission-requests', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['customer_name', 'customer_email', 'title', 'description']);
    }

    public function test_submission_stores_uploaded_reference_image(): void
    {
        Storage::fake('public');
        Mail::fake();

        $this->postJson('/api/v1/commission-requests', array_merge(
            $this->validPayload(),
            ['reference_image' => UploadedFile::fake()->image('reference.jpg', 800, 600)],
        ));

        $commission = CommissionRequest::first();
        $this->assertNotNull($commission?->reference_image_url);
        $this->assertStringContainsString('/storage/commissions/', $commission->reference_image_url);
    }

    public function test_admin_can_list_commission_requests(): void
    {
        CommissionRequest::factory()->count(3)->create();

        $this->asAdmin()
            ->getJson('/api/v1/commission-requests')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_admin_can_update_status_and_notes(): void
    {
        $commission = CommissionRequest::factory()->create(['status' => 'pending']);

        $this->asAdmin()
            ->patchJson("/api/v1/commission-requests/{$commission->id}", [
                'status' => 'quoted',
                'admin_notes' => 'Sent quote of $800 via email.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'quoted')
            ->assertJsonPath('data.admin_notes', 'Sent quote of $800 via email.');
    }

    public function test_invalid_status_is_rejected(): void
    {
        $commission = CommissionRequest::factory()->create();

        $this->asAdmin()
            ->patchJson("/api/v1/commission-requests/{$commission->id}", [
                'status' => 'made_up',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_anonymous_users_cannot_list_or_update(): void
    {
        $commission = CommissionRequest::factory()->create();

        $this->getJson('/api/v1/commission-requests')->assertStatus(401);
        $this->patchJson("/api/v1/commission-requests/{$commission->id}", ['status' => 'reviewing'])->assertStatus(401);
    }

    public function test_public_submit_returns_404_when_module_is_disabled(): void
    {
        SiteConfig::query()->update(['modules_enabled' => ['commissions' => false]]);

        $this->postJson('/api/v1/commission-requests', $this->validPayload())
            ->assertStatus(404);
    }

    public function test_admin_can_still_view_existing_records_when_module_is_disabled(): void
    {
        SiteConfig::query()->update(['modules_enabled' => ['commissions' => false]]);
        $commission = CommissionRequest::factory()->create();

        $this->asAdmin()
            ->getJson('/api/v1/commission-requests')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->asAdmin()
            ->patchJson("/api/v1/commission-requests/{$commission->id}", ['status' => 'cancelled'])
            ->assertOk();
    }
}

<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminAssistantApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $role = Role::create(['name' => 'admin']);
        $this->admin->roles()->attach($role);

        config(['services.gemini.key' => 'test-key']);
    }

    private function fakeGeminiReply(string $text): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => $text]]]],
                ],
            ]),
        ]);
    }

    public function test_admin_can_chat_with_assistant(): void
    {
        $this->fakeGeminiReply('Go to Products → Add a product.');

        $this->actingAs($this->admin)
            ->postJson('/api/v1/admin-assistant', ['message' => 'How do I add a product?'])
            ->assertOk()
            ->assertJsonPath('message', 'Go to Products → Add a product.');
    }

    public function test_history_is_forwarded_to_the_model(): void
    {
        $this->fakeGeminiReply('Sure.');

        $this->actingAs($this->admin)
            ->postJson('/api/v1/admin-assistant', [
                'message' => 'And how do I publish it?',
                'history' => [
                    ['role' => 'user', 'content' => 'How do I add a product?'],
                    ['role' => 'assistant', 'content' => 'Go to Products.'],
                ],
            ])
            ->assertOk();

        Http::assertSent(function ($request) {
            $contents = $request['contents'];

            // 2 history turns + the new user message; assistant maps to "model".
            return count($contents) === 3
                && $contents[0]['role'] === 'user'
                && $contents[1]['role'] === 'model'
                && $contents[2]['parts'][0]['text'] === 'And how do I publish it?';
        });
    }

    public function test_message_is_required(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/admin-assistant', ['message' => ''])
            ->assertStatus(422);
    }

    public function test_returns_503_when_api_key_missing(): void
    {
        config(['services.gemini.key' => null]);

        $this->actingAs($this->admin)
            ->postJson('/api/v1/admin-assistant', ['message' => 'Hi'])
            ->assertStatus(503);
    }

    public function test_returns_502_when_model_errors(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response('upstream error', 500),
        ]);

        $this->actingAs($this->admin)
            ->postJson('/api/v1/admin-assistant', ['message' => 'Hi'])
            ->assertStatus(502);
    }

    public function test_unauthenticated_cannot_chat(): void
    {
        $this->postJson('/api/v1/admin-assistant', ['message' => 'Hi'])
            ->assertStatus(401);
    }
}

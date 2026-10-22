<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReleaseReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_release_check_passes_when_database_and_schema_are_current(): void
    {
        $this->artisan('backend:release-check')
            ->expectsOutputToContain('Release check passed')
            ->assertSuccessful();

        foreach (['payment_webhook_events', 'subscription_webhook_events'] as $table) {
            $this->assertTrue(Schema::hasColumns($table, [
                'attempts',
                'processed_at',
                'failed_at',
                'last_error',
            ]));
        }
    }

    public function test_strict_release_check_rejects_test_runtime_configuration(): void
    {
        $this->artisan('backend:release-check', ['--strict' => true])
            ->expectsOutputToContain('Release check failed')
            ->assertFailed();
    }

    public function test_operational_schedules_remain_registered(): void
    {
        $commands = collect(app(Schedule::class)->events())
            ->map(fn ($event): string => (string) $event->command)
            ->implode("\n");

        $this->assertStringContainsString('payments:expire-stale --minutes=30', $commands);
        $this->assertStringContainsString('backorders:expire-tokens', $commands);
        $this->assertStringContainsString('subscriptions:expire', $commands);
    }

    public function test_health_endpoint_is_available(): void
    {
        $this->get('/up')->assertOk();
    }
}

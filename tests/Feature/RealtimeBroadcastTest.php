<?php

namespace Tests\Feature;

use App\Models\SiteConfig;
use App\Modules\Realtime\Events\ModelChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class RealtimeBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_broadcast_sends_changed_field_names_not_values(): void
    {
        // Reverb enforces the Pusher ~10KB per-event limit; large model values
        // (e.g. SiteConfig JSON blobs) must never be shipped in the broadcast.
        config(['realtime.enabled' => true]);

        $config = SiteConfig::create([]);

        Event::fake([ModelChanged::class]);

        $bigValue = str_repeat('x', 20_000);
        $config->update(['about_content' => $bigValue]);

        Event::assertDispatched(ModelChanged::class, function (ModelChanged $event) use ($bigValue) {
            $encoded = json_encode($event->broadcastWith());

            return $event->action === 'updated'
                && $event->modelType === 'SiteConfig'
                && in_array('about_content', $event->data['changed'] ?? [], true)
                && ! str_contains($encoded, $bigValue)   // the value itself is not broadcast
                && strlen($encoded) < 2_000;             // comfortably under Reverb's limit
        });
    }

    public function test_create_broadcast_carries_no_attribute_payload(): void
    {
        config(['realtime.enabled' => true]);

        Event::fake([ModelChanged::class]);

        SiteConfig::create(['about_content' => str_repeat('y', 20_000)]);

        Event::assertDispatched(ModelChanged::class, function (ModelChanged $event) {
            return $event->action === 'created'
                && $event->data === [];
        });
    }
}

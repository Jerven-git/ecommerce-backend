<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentWebhookEvent;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\Database\Transaction;
use App\Support\Tenancy\CurrentStore;
use App\Support\Webhooks\WebhookEventProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class CrossCuttingHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_status_cache_is_isolated_by_store(): void
    {
        [$storeA, $adminA] = $this->storeAdmin('Alpha');
        [$storeB, $adminB] = $this->storeAdmin('Beta');

        Order::factory()->create(['store_id' => $storeA->id, 'status' => 'pending']);
        Order::factory()->count(2)->create(['store_id' => $storeB->id, 'status' => 'processing']);

        Cache::flush();

        $this->actingAs($adminA)
            ->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonPath('status_counts.pending', 1)
            ->assertJsonMissingPath('status_counts.processing');

        app(CurrentStore::class)->clear();

        $this->actingAs($adminB)
            ->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonPath('status_counts.processing', 2)
            ->assertJsonMissingPath('status_counts.pending');
    }

    public function test_commerce_mutations_invalidate_order_and_dashboard_caches(): void
    {
        [$store, $admin] = $this->storeAdmin('Cache');
        Order::factory()->create(['store_id' => $store->id, 'status' => 'pending']);

        Cache::flush();

        $this->actingAs($admin)
            ->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonPath('status_counts.pending', 1);

        $this->actingAs($admin)
            ->getJson('/api/v1/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('data.stats.total_products', 0);

        Order::factory()->create(['store_id' => $store->id, 'status' => 'pending']);
        Product::factory()->create(['store_id' => $store->id]);

        app(CurrentStore::class)->clear();

        $this->actingAs($admin)
            ->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonPath('status_counts.pending', 2);

        $this->actingAs($admin)
            ->getJson('/api/v1/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('data.stats.total_products', 1);
    }

    public function test_failed_webhook_events_remain_retriable_and_completed_events_are_deduplicated(): void
    {
        Log::spy();

        $event = PaymentWebhookEvent::create([
            'provider' => 'stripe',
            'event_id' => 'evt_retry_contract',
            'event_type' => 'payment_intent.succeeded',
            'payload' => ['id' => 'evt_retry_contract'],
        ]);
        $processor = app(WebhookEventProcessor::class);

        try {
            $processor->process(
                $event,
                fn () => throw new RuntimeException('temporary provider failure'),
                ['provider' => 'stripe', 'store_id' => 42, 'stream' => 'payments'],
            );
            $this->fail('The processing failure should be rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('temporary provider failure', $exception->getMessage());
        }

        $event->refresh();
        $this->assertSame(1, $event->attempts);
        $this->assertNull($event->processed_at);
        $this->assertNotNull($event->failed_at);
        $this->assertSame(RuntimeException::class, $event->last_error);

        $runs = 0;
        $this->assertTrue($processor->process($event, function () use (&$runs): void {
            $runs++;
        }));
        $this->assertFalse($processor->process($event, function () use (&$runs): void {
            $runs++;
        }));

        $event->refresh();
        $this->assertSame(1, $runs);
        $this->assertSame(2, $event->attempts);
        $this->assertNotNull($event->processed_at);
        $this->assertNull($event->failed_at);
        $this->assertNull($event->last_error);

        Log::shouldHaveReceived('error')->once()->with(
            'Webhook processing failed',
            \Mockery::on(fn (array $context): bool => $context === [
                'provider' => 'stripe',
                'store_id' => 42,
                'stream' => 'payments',
                'event_id' => 'evt_retry_contract',
                'event_type' => 'payment_intent.succeeded',
                'attempt' => 1,
                'exception' => RuntimeException::class,
            ]),
        );
    }

    public function test_order_and_dashboard_reads_stay_within_query_budgets(): void
    {
        [$store, $admin] = $this->storeAdmin('Budget');
        $product = Product::factory()->create(['store_id' => $store->id]);
        $order = Order::factory()->create(['store_id' => $store->id]);
        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_price' => $product->price,
            'quantity' => 1,
            'subtotal' => $product->price,
        ]);

        Cache::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($admin)->getJson('/api/v1/orders')->assertOk();
        $orderQueries = count(DB::getQueryLog());

        DB::flushQueryLog();
        Cache::flush();
        app(CurrentStore::class)->clear();

        $this->actingAs($admin)->getJson('/api/v1/dashboard/stats')->assertOk();
        $dashboardQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(12, $orderQueries, "Order index used {$orderQueries} queries.");
        $this->assertLessThanOrEqual(18, $dashboardQueries, "Dashboard used {$dashboardQueries} queries.");
    }

    public function test_critical_transactions_have_a_bounded_concurrency_retry_policy(): void
    {
        $this->assertSame(3, Transaction::CONCURRENCY_ATTEMPTS);
    }

    /** @return array{Store, User} */
    private function storeAdmin(string $name): array
    {
        $store = Store::factory()->create(['name' => "{$name} Store"]);
        $admin = User::factory()->create([
            'is_admin' => true,
            'store_id' => $store->id,
        ]);
        $admin->roles()->attach(Role::firstOrCreate(['name' => 'admin']));

        return [$store, $admin];
    }
}

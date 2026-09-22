<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Store;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DashboardDemoSeeder extends Seeder
{
    private const DEMO_EMAIL_DOMAIN = 'chart-demo.test';

    /**
     * Seed lightweight demo orders + payments so the admin dashboard
     * Sales Analytics charts (orders, revenue, popular products) have
     * something to render. Re-runnable: previous demo rows are wiped first.
     *
     * Runs as part of `php artisan db:seed`, or standalone:
     *   php artisan db:seed --class=DashboardDemoSeeder
     */
    public function run(): void
    {
        $store = Store::query()->where('slug', Store::DEFAULT_SLUG)->firstOrFail();

        $this->clearPreviousDemoData();

        $products = Product::query()->get(['id', 'name', 'price', 'image_url'])->all();
        if (empty($products)) {
            $this->command->error('No products found. Run ProductSeeder first.');

            return;
        }

        // Deterministic demo data so charts look the same on every re-seed.
        mt_srand(1234);

        // 200 orders: a dozen today (hourly buckets for the Today view),
        // the rest spread across the past year weighted toward recent days
        // so the 7d/30d/year ranges all have shape.
        $daysAgoList = array_fill(0, 12, 0);
        for ($i = 0; $i < 188; $i++) {
            $daysAgoList[] = (int) floor(365 * (mt_rand() / mt_getrandmax()) ** 2);
        }

        $statusPool = array_merge(
            array_fill(0, 10, 'pending'),
            array_fill(0, 15, 'processing'),
            array_fill(0, 20, 'shipped'),
            array_fill(0, 45, 'delivered'),
            array_fill(0, 10, 'cancelled'),
        );

        $firstNames = ['Ava', 'Liam', 'Mia', 'Noah', 'Zoe', 'Eli', 'Nora', 'Kai', 'Ivy', 'Leo'];
        $lastNames = ['Cruz', 'Reyes', 'Santos', 'Bautista', 'Ocampo', 'Garcia', 'Mendoza', 'Torres', 'Tomas', 'Ramos'];

        $now = Carbon::now();
        $orderRows = [];
        $itemRows = [];
        $paymentRows = [];

        foreach ($daysAgoList as $daysAgo) {
            $createdAt = $daysAgo === 0
                    ? $now->copy()->subHours(mt_rand(0, (int) $now->format('H')))
                    : $now->copy()->subDays($daysAgo)->setTime(mt_rand(8, 20), mt_rand(0, 59));

            $first = $firstNames[array_rand($firstNames)];
            $last = $lastNames[array_rand($lastNames)];
            $status = $statusPool[array_rand($statusPool)];

            $picked = (array) array_rand($products, mt_rand(1, min(3, count($products))));
            $subtotal = 0;
            $items = [];
            foreach ($picked as $pIdx) {
                $product = $products[$pIdx];
                $qty = mt_rand(1, 3);
                $lineTotal = round((float) $product->price * $qty, 2);
                $subtotal += $lineTotal;
                $items[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_price' => $product->price,
                    'quantity' => $qty,
                    'subtotal' => $lineTotal,
                ];
            }
            $subtotal = round($subtotal, 2);
            $tax = round($subtotal * 0.08, 2);
            $shipping = mt_rand(0, 1) ? round(mt_rand(500, 1500) / 100, 2) : 0;
            $total = round($subtotal + $tax + $shipping, 2);

            $orderRows[] = [
                'store_id' => $store->id,
                'customer_name' => "{$first} {$last}",
                'customer_email' => strtolower("{$first}.{$last}".mt_rand(1, 99)).'@'.self::DEMO_EMAIL_DOMAIN,
                'customer_phone' => '555-01'.str_pad((string) mt_rand(0, 99), 2, '0', STR_PAD_LEFT).'-'.str_pad((string) mt_rand(0, 9999), 4, '0', STR_PAD_LEFT),
                'shipping_address' => mt_rand(100, 9999).' Demo St, Test City, TC 12345',
                'total_amount' => $total,
                'subtotal' => $subtotal,
                'tax_amount' => $tax,
                'shipping_amount' => $shipping,
                'discount_code' => null,
                'discount_amount' => 0,
                'status' => $status,
                'stock_deducted_at' => in_array($status, ['processing', 'shipped', 'delivered'], true) ? $createdAt : null,
                'created_at' => $createdAt,
            ];
            $orderIndex = count($orderRows) - 1;

            foreach ($items as $item) {
                $itemRows[] = array_merge($item, [
                    'order_index' => $orderIndex,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);
            }

            $paymentStatus = match ($status) {
                'pending' => mt_rand(0, 1) ? 'pending' : 'paid',
                'processing', 'shipped', 'delivered' => 'paid',
                default => 'failed',
            };

            $paymentRows[] = [
                'order_index' => $orderIndex,
                'provider' => 'stripe',
                'provider_ref' => 'demo_'.Str::random(16),
                'status' => $paymentStatus,
                'amount' => (int) round($total * 100),
                'currency' => 'USD',
                'public_token' => Str::random(32),
                'meta' => null,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];
        }

        $orderIds = [];
        foreach ($orderRows as $row) {
            $orderIds[] = DB::table('orders')->insertGetId($row);
        }

        foreach ($itemRows as $item) {
            DB::table('order_items')->insert([
                'order_id' => $orderIds[$item['order_index']],
                'product_id' => $item['product_id'],
                'product_name' => $item['product_name'],
                'product_price' => $item['product_price'],
                'quantity' => $item['quantity'],
                'subtotal' => $item['subtotal'],
                'created_at' => $item['created_at'],
                'updated_at' => $item['updated_at'],
            ]);
        }

        foreach ($paymentRows as $payment) {
            DB::table('payments')->insert([
                'order_id' => $orderIds[$payment['order_index']],
                'provider' => $payment['provider'],
                'provider_ref' => $payment['provider_ref'],
                'status' => $payment['status'],
                'amount' => $payment['amount'],
                'currency' => $payment['currency'],
                'public_token' => $payment['public_token'],
                'meta' => $payment['meta'],
                'created_at' => $payment['created_at'],
                'updated_at' => $payment['updated_at'],
            ]);
        }

        $this->command->info('Seeded '.count($orderRows).' demo orders with items + payments for the dashboard charts.');
    }

    private function clearPreviousDemoData(): void
    {
        $orderIds = DB::table('orders')
            ->where('customer_email', 'like', '%@'.self::DEMO_EMAIL_DOMAIN)
            ->pluck('id')
            ->all();

        if (empty($orderIds)) {
            return;
        }

        DB::table('payments')->whereIn('order_id', $orderIds)->delete();
        DB::table('order_items')->whereIn('order_id', $orderIds)->delete();
        DB::table('orders')->whereIn('id', $orderIds)->delete();

        $this->command->info('Cleared '.count($orderIds).' previous demo orders.');
    }
}

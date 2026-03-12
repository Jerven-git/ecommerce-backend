<?php

namespace Database\Seeders;

use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderStressSeeder extends Seeder
{
    public function run(): void
    {
        $totalOrders = 65_000;
        $chunkSize = 2_000;

        $products = Product::all(['id', 'name', 'price'])->toArray();

        if (empty($products)) {
            $this->command->error('No products found. Run ProductSeeder first.');
            return;
        }

        $statuses = ['pending', 'processing', 'shipped', 'delivered', 'cancelled'];
        $statusWeights = [
            'pending' => 10,
            'processing' => 15,
            'shipped' => 20,
            'delivered' => 45,
            'cancelled' => 10,
        ];
        $weightedStatuses = [];
        foreach ($statusWeights as $status => $weight) {
            for ($i = 0; $i < $weight; $i++) {
                $weightedStatuses[] = $status;
            }
        }

        $providers = ['stripe', 'paypal', 'square', 'cash'];
        $paymentStatuses = ['pending', 'paid', 'failed', 'expired'];

        $firstNames = ['James', 'Mary', 'John', 'Patricia', 'Robert', 'Jennifer', 'Michael', 'Linda', 'David', 'Elizabeth', 'William', 'Barbara', 'Richard', 'Susan', 'Joseph', 'Jessica', 'Thomas', 'Sarah', 'Charles', 'Karen', 'Daniel', 'Lisa', 'Matthew', 'Nancy', 'Anthony', 'Betty', 'Mark', 'Margaret', 'Donald', 'Sandra'];
        $lastNames = ['Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis', 'Rodriguez', 'Martinez', 'Hernandez', 'Lopez', 'Gonzalez', 'Wilson', 'Anderson', 'Thomas', 'Taylor', 'Moore', 'Jackson', 'Martin'];
        $domains = ['gmail.com', 'yahoo.com', 'outlook.com', 'hotmail.com', 'icloud.com'];
        $streets = ['Main St', 'Oak Ave', 'Elm St', 'Park Blvd', 'Cedar Ln', 'Maple Dr', 'Pine St', 'Washington Ave', 'Lake Rd', 'Hill St'];
        $cities = ['New York', 'Los Angeles', 'Chicago', 'Houston', 'Phoenix', 'San Antonio', 'San Diego', 'Dallas', 'Austin', 'Miami', 'Denver', 'Seattle', 'Portland', 'Atlanta', 'Boston'];
        $states = ['NY', 'CA', 'IL', 'TX', 'AZ', 'FL', 'CO', 'WA', 'OR', 'GA', 'MA'];

        $startDate = Carbon::now()->subMonths(12);
        $endDate = Carbon::now();
        $dateRangeSeconds = $endDate->diffInSeconds($startDate);

        $this->command->info("Seeding {$totalOrders} orders in chunks of {$chunkSize}...");

        $orderIdStart = (int) DB::table('orders')->max('id') + 1;
        $orderIdCurrent = $orderIdStart;

        for ($offset = 0; $offset < $totalOrders; $offset += $chunkSize) {
            $batchSize = min($chunkSize, $totalOrders - $offset);

            $orderRows = [];
            $itemRows = [];
            $paymentRows = [];

            for ($i = 0; $i < $batchSize; $i++) {
                $firstName = $firstNames[array_rand($firstNames)];
                $lastName = $lastNames[array_rand($lastNames)];
                $customerName = "{$firstName} {$lastName}";
                $customerEmail = strtolower($firstName) . '.' . strtolower($lastName) . rand(1, 999) . '@' . $domains[array_rand($domains)];
                $customerPhone = '555-' . str_pad(rand(0, 999), 3, '0', STR_PAD_LEFT) . '-' . str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);

                $street = rand(100, 9999) . ' ' . $streets[array_rand($streets)];
                $city = $cities[array_rand($cities)];
                $state = $states[array_rand($states)];
                $zip = str_pad(rand(10000, 99999), 5, '0', STR_PAD_LEFT);
                $shippingAddress = "{$street}, {$city}, {$state} {$zip}";

                $status = $weightedStatuses[array_rand($weightedStatuses)];

                $numItems = rand(1, 4);
                $chosenProducts = array_rand($products, min($numItems, count($products)));
                if (!is_array($chosenProducts)) {
                    $chosenProducts = [$chosenProducts];
                }

                $subtotal = 0;
                $orderItems = [];
                foreach ($chosenProducts as $pIdx) {
                    $product = $products[$pIdx];
                    $qty = rand(1, 3);
                    $itemSubtotal = round($product['price'] * $qty, 2);
                    $subtotal += $itemSubtotal;

                    $orderItems[] = [
                        'product_id' => $product['id'],
                        'product_name' => $product['name'],
                        'product_price' => $product['price'],
                        'quantity' => $qty,
                        'subtotal' => $itemSubtotal,
                    ];
                }

                $taxAmount = round($subtotal * 0.08, 2);
                $shippingAmount = rand(0, 1) ? round(rand(500, 2500) / 100, 2) : 0;
                $discountAmount = rand(0, 100) < 15 ? round($subtotal * (rand(5, 25) / 100), 2) : 0;
                $discountCode = $discountAmount > 0 ? 'PROMO' . rand(10, 99) : null;
                $totalAmount = round($subtotal + $taxAmount + $shippingAmount - $discountAmount, 2);
                if ($totalAmount < 0) {
                    $totalAmount = 0;
                }

                $createdAt = $startDate->copy()->addSeconds(rand(0, $dateRangeSeconds));
                $stockDeductedAt = in_array($status, ['processing', 'shipped', 'delivered']) ? $createdAt->copy()->addMinutes(rand(1, 30)) : null;

                $orderId = $orderIdCurrent++;

                $orderRows[] = [
                    'id' => $orderId,
                    'customer_name' => $customerName,
                    'customer_email' => $customerEmail,
                    'customer_phone' => $customerPhone,
                    'shipping_address' => $shippingAddress,
                    'total_amount' => $totalAmount,
                    'subtotal' => $subtotal,
                    'tax_amount' => $taxAmount,
                    'shipping_amount' => $shippingAmount,
                    'discount_code' => $discountCode,
                    'discount_amount' => $discountAmount,
                    'status' => $status,
                    'stock_deducted_at' => $stockDeductedAt,
                    'created_at' => $createdAt,
                ];

                foreach ($orderItems as $item) {
                    $itemRows[] = array_merge($item, [
                        'order_id' => $orderId,
                        'created_at' => $createdAt,
                        'updated_at' => $createdAt,
                    ]);
                }

                $provider = $providers[array_rand($providers)];
                $paymentStatus = match ($status) {
                    'pending' => rand(0, 1) ? 'pending' : null,
                    'processing', 'shipped', 'delivered' => 'paid',
                    'cancelled' => ['failed', 'expired'][array_rand(['failed', 'expired'])],
                    default => 'pending',
                };

                if ($paymentStatus !== null) {
                    $paymentRows[] = [
                        'order_id' => $orderId,
                        'provider' => $provider,
                        'provider_ref' => $provider !== 'cash' ? 'ref_' . Str::random(24) : null,
                        'status' => $paymentStatus,
                        'amount' => (int) round($totalAmount * 100),
                        'currency' => 'USD',
                        'public_token' => Str::random(32),
                        'meta' => null,
                        'created_at' => $createdAt,
                        'updated_at' => $createdAt,
                    ];
                }
            }

            DB::table('orders')->insert($orderRows);

            foreach (array_chunk($itemRows, 3000) as $itemChunk) {
                DB::table('order_items')->insert($itemChunk);
            }

            foreach (array_chunk($paymentRows, 3000) as $paymentChunk) {
                DB::table('payments')->insert($paymentChunk);
            }

            $done = $offset + $batchSize;
            $this->command->info("  Inserted {$done} / {$totalOrders} orders");
        }

        $this->command->info("Done. Seeded {$totalOrders} orders with items and payments.");
    }
}

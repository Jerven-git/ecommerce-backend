<?php

namespace Database\Seeders;

use App\Models\Post;
use App\Models\PostCategory;
use Illuminate\Database\Seeder;

class BlogSeeder extends Seeder
{
    public function run(): void
    {
        $newArrivals = PostCategory::create([
            'name' => 'New Arrivals',
            'slug' => 'new-arrivals',
            'gradient_from' => '#3B82F6',
            'gradient_to' => '#8B5CF6',
            'image_url' => 'https://picsum.photos/seed/cat-new-arrivals/1200/400',
            'sort_order' => 1,
        ]);

        $buyingGuides = PostCategory::create([
            'name' => 'Buying Guides',
            'slug' => 'buying-guides',
            'gradient_from' => '#F59E0B',
            'gradient_to' => '#EA580C',
            'image_url' => 'https://picsum.photos/seed/cat-guides/1200/400',
            'sort_order' => 2,
        ]);

        $customerStories = PostCategory::create([
            'name' => 'Customer Stories',
            'slug' => 'customer-stories',
            'gradient_from' => '#EC4899',
            'gradient_to' => '#EF4444',
            'image_url' => 'https://picsum.photos/seed/cat-stories/1200/400',
            'sort_order' => 3,
        ]);

        $posts = [
            [
                'title' => 'New In: 2026 Spring Smartwatch Lineup',
                'excerpt' => 'Three new fitness watches join the lineup this season — lighter cases, longer battery life, and a fresh strap palette.',
                'category_id' => $newArrivals->id,
                'is_featured' => true,
                'author_name' => 'Editorial Team',
                'body' => <<<'MD'
We've spent the last six months refining the next generation of our fitness watch. This spring, three new models arrive — each lighter, longer-lasting, and more comfortable to wear all day.

## What's new

- **Aluminium Sport** — 14% lighter case with a recycled-fibre strap
- **Titanium Pro** — sapphire crystal, 7-day battery, 100m water rating
- **Classic Leather Edition** — vegetable-tanned strap and a polished steel back

## Available now

All three drop on the storefront this Friday. Members on our newsletter get early access starting Wednesday at 9am — [sign up here](/) if you haven't already.

> Designed for the wrist, refined for daily life.
MD,
            ],
            [
                'title' => 'How to Choose Your First Mechanical Watch',
                'excerpt' => 'Automatic, hand-wound, or quartz? A practical guide for buying your first proper watch — without the jargon.',
                'category_id' => $buyingGuides->id,
                'is_featured' => true,
                'author_name' => 'Maya Chen',
                'body' => <<<'MD'
Walking into the world of mechanical watches can feel like learning a second language. Here's the short version, in plain English.

## The three movement types

- **Quartz** — battery powered, accurate to seconds per month, and the most affordable
- **Automatic** — winds itself from the motion of your wrist; the most popular mechanical option
- **Hand-wound** — you wind the crown yourself each day; usually the slimmest and most traditional

## What to look for under $500

1. A reputable case material (316L stainless steel at minimum)
2. Sapphire crystal — scratch-resistant and worth every dollar
3. At least 3ATM water resistance for everyday wear
4. A movement you can have serviced locally

## Skip the hype

Marketing budgets are huge in this category. Read independent reviews, ask in collector forums, and never buy a watch online without a return policy you trust.
MD,
            ],
            [
                'title' => 'Customer Spotlight: Jamie\'s 5-Year Collection',
                'excerpt' => 'How one customer built a meaningful collection of five watches — one for each chapter of his life.',
                'category_id' => $customerStories->id,
                'is_featured' => true,
                'author_name' => 'Editorial Team',
                'body' => <<<'MD'
Jamie ordered his first watch from us in 2021 — a simple 38mm dress watch for a job interview. Five years and four watches later, he sat down with our team to share what each one means.

## The collection

1. **The dress watch** — for the job he eventually got
2. **The dive watch** — for the honeymoon in Greece
3. **The chronograph** — for the marathon he trained nine months for
4. **The smartwatch** — for everything else
5. **The grail** — a vintage piece, "for the next twenty years"

## The lesson

> "Every watch in the box is a reminder of a moment I want to keep close. They've stopped being purchases and started being chapters."

We love hearing stories like Jamie's. If you have one, [tell us about it](/contact) — we're always looking for the next spotlight.
MD,
            ],
            [
                'title' => 'Free Shipping Now on Orders Over $50',
                'excerpt' => 'A long-requested change — free standard shipping on every order over $50, automatically applied at checkout.',
                'category_id' => $newArrivals->id,
                'is_featured' => false,
                'author_name' => 'Editorial Team',
                'body' => <<<'MD'
You asked, we listened. Starting today, every order over $50 ships free — no codes, no fine print.

## What changed

- **Free standard shipping** on orders over $50 (was $75)
- **Express upgrade** still available for $9.99
- **International shipping** thresholds unchanged for now — we're working on it

## Why we did it

We ran the numbers and the previous threshold was friction for too many of you. Lower bar, simpler decision. That's it.
MD,
            ],
            [
                'title' => 'Sapphire vs Mineral Crystal: What\'s Actually Worth Paying For',
                'excerpt' => 'Sapphire is harder, mineral is cheaper to replace. Which one belongs on your next watch?',
                'category_id' => $buyingGuides->id,
                'is_featured' => false,
                'author_name' => 'Maya Chen',
                'body' => <<<'MD'
The crystal is the glass that protects the dial. There are three common types — and the differences matter more than the marketing suggests.

## The three options

- **Acrylic** — soft, scratches easily, polishes back to clear with toothpaste. Common on vintage and budget watches.
- **Mineral** — hardened glass. Decent scratch resistance, cheap to replace if it cracks.
- **Sapphire** — second-hardest material on Earth after diamond. Effectively scratch-proof in daily life.

## Our take

If you're spending more than $200, sapphire is non-negotiable. Below that, mineral is a perfectly sensible choice — especially for a beater you don't want to baby.
MD,
            ],
            [
                'title' => 'The 2026 Father\'s Day Gift Guide',
                'excerpt' => 'Eight ideas across every budget — from a $19 graphic tee to a $299 monitor for the home office.',
                'category_id' => $buyingGuides->id,
                'is_featured' => false,
                'author_name' => 'Sam Rivera',
                'body' => <<<'MD'
Father's Day always sneaks up on us. To save you the panic, here are eight gifts from the storefront — sorted by budget.

## Under $50

- **Cotton Graphic T-Shirt** — soft, durable, easy win
- **Wireless Bluetooth Earbuds** — under $50 and noise-cancelling

## $50–$150

- **Standing Desk Lamp** — for the work-from-home dad
- **Stainless Steel Cookware Set** — if he's the cook of the house

## $150+

- **Smart Fitness Watch** — heart rate, sleep, music
- **27-inch 4K Monitor** — for the office upgrade

Order by Wednesday for guaranteed delivery before the weekend.
MD,
            ],
            [
                'title' => 'Behind the Bench: How We Test Water Resistance',
                'excerpt' => 'A look inside our QC lab — pressure chambers, temperature swings, and why "5ATM" actually means something.',
                'category_id' => $newArrivals->id,
                'is_featured' => false,
                'author_name' => 'Editorial Team',
                'body' => <<<'MD'
Every watch we ship is pressure-tested before it leaves the warehouse. Here's how the process works — and why most "100m" ratings on the internet aren't worth the marketing copy they're written on.

## The test bench

1. **Static pressure test** — held at rated depth for 60 seconds
2. **Dynamic pressure test** — pressure cycled to simulate motion
3. **Thermal shock** — 40°C to 5°C transition before re-test
4. **Random sample audit** — 1 in every 50 units gets the full battery

## What the ratings really mean

- **3ATM** — splash-proof, that's it. Don't shower in it.
- **5ATM** — okay for swimming pool laps, not diving
- **10ATM** — recreational snorkelling, beach swims
- **20ATM+** — actual dive territory; we test to twice the rated depth

When in doubt: take the watch off before it gets wet.
MD,
            ],
            [
                'title' => '6 Months In: A Reader\'s Smart Fitness Watch Review',
                'excerpt' => 'Reader Priya breaks down what works, what doesn\'t, and what surprised her after six months of daily wear.',
                'category_id' => $customerStories->id,
                'is_featured' => false,
                'author_name' => 'Priya Shah',
                'body' => <<<'MD'
I bought the Smart Fitness Watch six months ago to replace a much fancier (and much heavier) competitor. Here's the unvarnished review.

## The good

- Battery genuinely lasts a full week, even with sleep tracking on
- The bluetooth audio handoff between phone and watch is seamless
- Heart rate tracking matches my chest strap within 2 bpm during workouts

## The not-so-good

- The default watch faces are limited; install third-party ones immediately
- Notifications are slow to clear if you have too many at once
- The aluminium case scratches faster than I'd like

## Six-month verdict

Would I buy it again? Yes — at this price, nothing comes close.

> Disclaimer: I bought this watch with my own money. The team didn't see this review until it went live.
MD,
            ],
            [
                'title' => 'How to Resize a Metal Bracelet — A Beginner\'s Guide',
                'excerpt' => 'Done in under 20 minutes with a $10 toolkit. A step-by-step photo guide for first-timers.',
                'category_id' => $buyingGuides->id,
                'is_featured' => false,
                'author_name' => 'Marco Liu',
                'body' => <<<'MD'
A loose bracelet is one of the most common reasons returns come back to us — and one of the easiest things to fix yourself at home.

## What you'll need

- A spring-bar pin pusher (the $10 one from any hardware site is fine)
- A soft cloth to lay the watch on
- 20 minutes and decent light

## The steps

1. Lay the watch dial-down on the cloth
2. Find the arrows on the underside of the bracelet links — they tell you which direction the pin pushes out
3. Push the pin out from the marked side
4. Slide the link out
5. Re-insert the pin from the original direction
6. Repeat on the other side so the clasp stays centred

## When to ask a jeweller

If your bracelet uses screw links instead of pin links, take it to a watchmaker — the screws strip easily without proper tools and you'll spend more replacing them than the resize would have cost.
MD,
            ],
            [
                'title' => 'We\'re Now Shipping to 35 New Countries',
                'excerpt' => 'International expansion is live. Here\'s the full list, the new pricing, and what\'s changed at customs.',
                'category_id' => $newArrivals->id,
                'is_featured' => false,
                'author_name' => 'Editorial Team',
                'body' => <<<'MD'
After months of warehouse and carrier work, we're now shipping to 35 new countries across Europe, Southeast Asia, and the Middle East.

## What this means for you

- **Faster delivery** — most regions now arrive in 5–9 business days
- **Local currency at checkout** — auto-detected, with USD as fallback
- **Customs handled** — DDP shipping in 28 of the 35 new countries

## Where we're not yet

A handful of regions still have regulatory or carrier issues — we're working on Brazil, India, and Egypt next quarter. Get notified when your country goes live by adding your address to your account.
MD,
            ],
        ];

        foreach ($posts as $i => $p) {
            $seed = 'post-'.($i + 1);

            Post::create([
                'slug' => Post::generateUniqueSlug($p['title']),
                'title' => $p['title'],
                'excerpt' => $p['excerpt'],
                'body' => $p['body'],
                'author_name' => $p['author_name'],
                'category_id' => $p['category_id'],
                'cover_image_url' => "https://picsum.photos/seed/{$seed}/1600/900",
                'cover_alt_text' => $p['title'],
                'seo_title' => $p['title'],
                'seo_description' => $p['excerpt'],
                'og_image_url' => "https://picsum.photos/seed/{$seed}-og/1200/630",
                'is_published' => true,
                'is_featured' => $p['is_featured'],
                'published_at' => now()->subDays(random_int(1, 90)),
            ]);
        }
    }
}

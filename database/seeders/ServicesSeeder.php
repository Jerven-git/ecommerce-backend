<?php

namespace Database\Seeders;

use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\SiteConfig;
use Illuminate\Database\Seeder;

class ServicesSeeder extends Seeder
{
    public function run(): void
    {
        $config = SiteConfig::first() ?? SiteConfig::create([]);

        $config->update([
            'services_overlay_color' => '#0f172a',
            'services_overlay_opacity' => 55,
            'services_page' => [
                'header' => [
                    'label' => 'CARE & PERSONALISATION',
                    'heading' => 'More than just a checkout',
                    'subtitle' => 'Free shipping, lifetime warranty, custom engraving — every service that comes with your purchase, all in one place.',
                    'primary_cta' => [
                        'label' => 'Shop now',
                        'link' => '/shop',
                    ],
                    'secondary_cta' => [
                        'label' => 'Contact us',
                        'link' => '/contact',
                    ],
                ],
                'summary' => [
                    'items' => [
                        [
                            'title' => 'Free shipping & returns',
                            'description' => 'Free standard shipping on orders over $50. Returns are free for 30 days, no questions asked.',
                        ],
                        [
                            'title' => 'Lifetime warranty',
                            'description' => 'Every watch and electronic we sell is covered for life against manufacturing defects.',
                        ],
                        [
                            'title' => 'Personal touches',
                            'description' => 'Custom engraving, premium gift wrap, and handwritten notes — make every order feel made for them.',
                        ],
                    ],
                ],
                'stats' => [
                    'items' => [
                        [
                            'value' => '500K+',
                            'label' => 'Orders shipped to customers across 35 countries',
                        ],
                        [
                            'value' => '4.9 / 5',
                            'label' => 'Average customer rating across 12,000 verified reviews',
                        ],
                        [
                            'value' => '24h',
                            'label' => 'Average response time to support enquiries, 7 days a week',
                        ],
                    ],
                ],
                'cta' => [
                    'heading' => 'Need help with an order?',
                    'subtitle' => 'Our support team replies in under 24 hours, every day of the week.',
                    'button_label' => 'Contact support',
                    'button_link' => '/contact',
                ],
            ],
        ]);

        $customerCare = ServiceCategory::create([
            'name' => 'Customer Care',
            'slug' => 'customer-care',
            'gradient_from' => '#0EA5E9',
            'gradient_to' => '#1E3A8A',
            'image_url' => 'https://picsum.photos/seed/svc-cat-care/1200/400',
            'sort_order' => 0,
        ]);

        $personalisation = ServiceCategory::create([
            'name' => 'Personalisation',
            'slug' => 'personalisation',
            'gradient_from' => '#F59E0B',
            'gradient_to' => '#B91C1C',
            'image_url' => 'https://picsum.photos/seed/svc-cat-personalisation/1200/400',
            'sort_order' => 1,
        ]);

        $services = [
            [
                'eyebrow' => 'Shipping',
                'title' => 'Free shipping on orders over $50',
                'description' => 'Standard shipping is free on every order over $50, automatically. Express upgrades available at checkout.',
                'cta_label' => 'Shop now',
                'cta_link' => '/shop',
                'category_id' => $customerCare->id,
                'body' => <<<'MD'
We've kept it simple. Spend $50 or more and standard shipping is on us — no codes, no hidden thresholds.

## What you get

- **Standard (3–5 business days)** — free over $50, $4.99 below
- **Express (1–2 business days)** — flat $9.99 anywhere we ship
- **International** — calculated at checkout, with DDP customs handling in 28 countries

## Order tracking

Every order ships with a tracking link delivered the moment it leaves our warehouse. If anything goes sideways in transit, our support team has it before you do.
MD,
            ],
            [
                'eyebrow' => 'Warranty',
                'title' => 'Lifetime warranty on every watch & electronic',
                'description' => 'Manufacturing defects are covered for life. Send it back, we fix or replace — no fine print.',
                'cta_label' => 'See coverage',
                'cta_link' => '/contact',
                'category_id' => $customerCare->id,
                'body' => <<<'MD'
Buy something from us, keep it for life. If a manufacturing defect ever shows up, we'll repair or replace it — no questions, no time limits.

## What's covered

- Movement defects (mechanical and quartz)
- Battery failures within the first 5 years
- Crystal cracking under normal use
- Strap and bracelet hardware

## What's not covered

- Accidental damage (drops, water beyond rated depth)
- Normal wear on straps after 24 months
- Modifications by third-party watchmakers

Register your watch in your account within 30 days of delivery to activate the warranty automatically.
MD,
            ],
            [
                'eyebrow' => 'Returns',
                'title' => '30-day money-back guarantee',
                'description' => 'Not the right fit? Return it within 30 days for a full refund, including the return label.',
                'cta_label' => 'Start a return',
                'cta_link' => '/contact',
                'category_id' => $customerCare->id,
                'body' => <<<'MD'
We want you to love what you bought. If you don't, send it back within 30 days for a full refund.

## How returns work

1. Open the order in your account, click **Start a return**
2. We email you a prepaid return label within 60 seconds
3. Drop it at any local carrier point
4. Refund processed within 3 business days of receipt

## Conditions

- Item must be unworn and in original packaging
- Engraved or personalised orders are final sale (we'll tell you before checkout)
- Sale-priced items are returnable for store credit only
MD,
            ],
            [
                'eyebrow' => 'Engraving',
                'title' => 'Custom engraving on the case back',
                'description' => 'Add a name, a date, or a message — engraved by our in-house team and ready in 5 business days.',
                'cta_label' => 'Add to your order',
                'cta_link' => '/shop',
                'category_id' => $personalisation->id,
                'body' => <<<'MD'
Personalise any watch with up to 40 characters of laser engraving on the case back. A small touch that turns a gift into a keepsake.

## What you can engrave

- Names, initials, dates
- Coordinates (lat / long)
- Short quotes or messages
- Simple icons (heart, star, infinity)

## Lead time and pricing

- **+5 business days** added to the standard delivery estimate
- **$15** flat fee, regardless of message length
- **Free** when added to orders over $300

Note: engraved orders are final sale.
MD,
            ],
            [
                'eyebrow' => 'Gift wrapping',
                'title' => 'Premium gift wrap & handwritten note',
                'description' => 'Linen-wrapped box, satin ribbon, and a real handwritten card — for $8 you don\'t have to do a thing.',
                'cta_label' => 'Add at checkout',
                'cta_link' => '/shop',
                'category_id' => $personalisation->id,
                'body' => <<<'MD'
Skip the wrapping paper meltdown. We'll wrap your gift, attach a handwritten note, and ship it ready to give.

## What\'s included

- Linen-wrapped presentation box
- Satin ribbon in your choice of colour
- Real handwritten note (up to 200 characters)
- Optional gift receipt — no prices shown

## How to add it

Pick **Gift wrap** in the cart, choose a ribbon colour, and type the message. We'll handle the rest. Add $8 per item.
MD,
            ],
        ];

        foreach ($services as $i => $s) {
            $seed = 'service-'.($i + 1);

            Service::create([
                'slug' => Service::generateUniqueSlug($s['title']),
                'title' => $s['title'],
                'eyebrow' => $s['eyebrow'],
                'description' => $s['description'],
                'body' => $s['body'],
                'cta_label' => $s['cta_label'],
                'cta_link' => $s['cta_link'],
                'category_id' => $s['category_id'],
                'cover_image_url' => "https://picsum.photos/seed/{$seed}/1600/900",
                'cover_alt_text' => $s['title'],
                'seo_title' => $s['title'],
                'seo_description' => $s['description'],
                'og_image_url' => "https://picsum.photos/seed/{$seed}-og/1200/630",
                'is_published' => true,
                'is_featured' => $i === 0,
                'published_at' => now(),
                'sort_order' => $i,
            ]);
        }

        $this->command->info('Services page content seeded.');
    }
}

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
                    'label' => 'TRUSTED BY 3M+ CUSTOMERS',
                    'heading' => 'Services, done your way',
                    'subtitle' => 'Work with experts across every stage of your business — from setup to scale. Whatever plan you choose, our team has you covered.',
                    'primary_cta' => [
                        'label' => 'Get started',
                        'link' => '/contact',
                    ],
                    'secondary_cta' => [
                        'label' => 'Learn more',
                        'link' => '/about',
                    ],
                ],
                'summary' => [
                    'items' => [
                        [
                            'title' => 'Hands-on consulting',
                            'description' => 'Senior advisors who embed with your team and ship alongside you.',
                        ],
                        [
                            'title' => 'Fast turnaround',
                            'description' => 'Kick off in under a week with a clear scope and predictable milestones.',
                        ],
                        [
                            'title' => 'Expert support',
                            'description' => 'Pre-vetted specialists in design, engineering, and growth on demand.',
                        ],
                    ],
                ],
                'stats' => [
                    'items' => [
                        [
                            'value' => '3 million+',
                            'label' => 'Customers trust us to manage and grow their business',
                        ],
                        [
                            'value' => '$100+ billion',
                            'label' => 'In assets under management across clients of every size',
                        ],
                        [
                            'value' => '24/7',
                            'label' => 'Dedicated support, with on-call experts when you need them',
                        ],
                    ],
                ],
                'groups' => [
                    [
                        'heading' => 'For your everyday operations',
                        'items' => [
                            [
                                'eyebrow' => 'Setup & onboarding',
                                'title' => 'Launch without the headaches',
                                'description' => 'Skip the months of back-and-forth. Our setup team gets you fully operational — store, payments, shipping, taxes — in days, not quarters.',
                                'image_url' => '',
                                'cta_label' => 'Start onboarding',
                                'cta_link' => '/contact',
                            ],
                            [
                                'eyebrow' => 'Managed fulfillment',
                                'title' => 'Orders handled end-to-end',
                                'description' => 'From pick-and-pack to last-mile delivery, our logistics partners handle every touchpoint so you can focus on the product and the customer.',
                                'image_url' => '',
                                'cta_label' => 'See how it works',
                                'cta_link' => '/contact',
                            ],
                        ],
                    ],
                    [
                        'heading' => 'For your growth and strategy',
                        'items' => [
                            [
                                'eyebrow' => 'Strategic consulting',
                                'title' => 'Expert guidance, tailored to you',
                                'description' => 'Work one-on-one with senior consultants who understand your industry. Whatever plan you choose, you get a dedicated advisor focused on your outcomes.',
                                'image_url' => '',
                                'cta_label' => 'Book a consultation',
                                'cta_link' => '/contact',
                            ],
                            [
                                'eyebrow' => 'Growth & performance',
                                'title' => 'Turn visitors into loyal customers',
                                'description' => 'Our growth team runs full-funnel experiments — acquisition, conversion, retention — so every channel compounds instead of competing.',
                                'image_url' => '',
                                'cta_label' => 'Grow with us',
                                'cta_link' => '/contact',
                            ],
                            [
                                'eyebrow' => 'Custom integrations',
                                'title' => 'Built to fit your stack',
                                'description' => 'Need to plug into an ERP, a legacy CRM, or a bespoke analytics pipeline? Our engineers build and maintain the connectors so your team never babysits infrastructure.',
                                'image_url' => '',
                                'cta_label' => 'Talk to engineering',
                                'cta_link' => '/contact',
                            ],
                        ],
                    ],
                ],
                'cta' => [
                    'heading' => 'Ready to see what we can do for you?',
                    'subtitle' => 'Tell us about your goals and we\'ll map out a plan in the first call — no pressure, no commitment.',
                    'button_label' => 'Book a call',
                    'button_link' => '/contact',
                ],
            ],
        ]);

        $operations = ServiceCategory::create([
            'name' => 'Operations',
            'slug' => 'operations',
            'gradient_from' => '#0EA5E9',
            'gradient_to' => '#1E3A8A',
            'sort_order' => 0,
        ]);

        $growth = ServiceCategory::create([
            'name' => 'Growth & Strategy',
            'slug' => 'growth-and-strategy',
            'gradient_from' => '#F59E0B',
            'gradient_to' => '#B91C1C',
            'sort_order' => 1,
        ]);

        $services = [
            [
                'eyebrow' => 'Setup & onboarding',
                'title' => 'Launch without the headaches',
                'description' => 'Skip the months of back-and-forth. Our setup team gets you fully operational — store, payments, shipping, taxes — in days, not quarters.',
                'cta_label' => 'Start onboarding',
                'category_id' => $operations->id,
            ],
            [
                'eyebrow' => 'Managed fulfillment',
                'title' => 'Orders handled end-to-end',
                'description' => 'From pick-and-pack to last-mile delivery, our logistics partners handle every touchpoint so you can focus on the product and the customer.',
                'cta_label' => 'See how it works',
                'category_id' => $operations->id,
            ],
            [
                'eyebrow' => 'Strategic consulting',
                'title' => 'Expert guidance, tailored to you',
                'description' => 'Work one-on-one with senior consultants who understand your industry. Whatever plan you choose, you get a dedicated advisor focused on your outcomes.',
                'cta_label' => 'Book a consultation',
                'category_id' => $growth->id,
            ],
            [
                'eyebrow' => 'Growth & performance',
                'title' => 'Turn visitors into loyal customers',
                'description' => 'Our growth team runs full-funnel experiments — acquisition, conversion, retention — so every channel compounds instead of competing.',
                'cta_label' => 'Grow with us',
                'category_id' => $growth->id,
            ],
            [
                'eyebrow' => 'Custom integrations',
                'title' => 'Built to fit your stack',
                'description' => 'Need to plug into an ERP, a legacy CRM, or a bespoke analytics pipeline? Our engineers build and maintain the connectors so your team never babysits infrastructure.',
                'cta_label' => 'Talk to engineering',
                'category_id' => $growth->id,
            ],
        ];

        foreach ($services as $i => $s) {
            Service::create([
                'slug' => Service::generateUniqueSlug($s['title']),
                'title' => $s['title'],
                'eyebrow' => $s['eyebrow'],
                'description' => $s['description'],
                'cta_label' => $s['cta_label'],
                'cta_link' => '/contact',
                'category_id' => $s['category_id'],
                'is_published' => true,
                'is_featured' => $i === 0,
                'published_at' => now(),
                'sort_order' => $i,
            ]);
        }

        $this->command->info('Services page content seeded!');
    }
}

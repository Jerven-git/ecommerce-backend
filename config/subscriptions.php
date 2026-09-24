<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Renewal Grace Period
    |--------------------------------------------------------------------------
    |
    | Days a store stays usable after its paid period ends while a renewal is
    | still outstanding. After this window the daily `subscriptions:expire`
    | command (or the gateway's `customer.subscription.deleted` webhook) flips
    | the store to `expired` and re-engages the access gate.
    |
    */

    'grace_period_days' => (int) env('SUBSCRIPTION_GRACE_PERIOD_DAYS', 3),

    /*
    |--------------------------------------------------------------------------
    | Sellable Plans
    |--------------------------------------------------------------------------
    |
    | Seed values for the priced plans (subscription_plans table). Prices are in
    | cents. The setup fee is charged once with the first period; the recurring
    | price is billed each `interval` thereafter via the gateway. The yearly
    | plan is priced as ~2 months free against the monthly rate.
    |
    */

    'plans' => [
        'standard' => [
            'name' => 'Standard',
            'slug' => 'standard',
            'interval' => 'monthly',
            'price_cents' => (int) env('SUBSCRIPTION_PRICE_CENTS', 20000),
            'setup_fee_cents' => (int) env('SUBSCRIPTION_SETUP_FEE_CENTS', 0),
            'features' => [
                'Unlimited products',
                'Custom domain',
                'Order management',
                'Email support',
            ],
        ],
        'standard-yearly' => [
            'name' => 'Standard (Yearly)',
            'slug' => 'standard-yearly',
            'interval' => 'yearly',
            'price_cents' => (int) env('SUBSCRIPTION_YEARLY_PRICE_CENTS', 200000),
            'setup_fee_cents' => (int) env('SUBSCRIPTION_YEARLY_SETUP_FEE_CENTS', 0),
            'features' => [
                'Unlimited products',
                'Custom domain',
                'Order management',
                'Email support',
                '2 months free (vs monthly)',
            ],
        ],
    ],

];

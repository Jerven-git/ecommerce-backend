<?php

namespace App\Support\Subscriptions;

use Stripe\StripeClient;

/**
 * Thin wrapper around the platform's Stripe account for subscription billing.
 * Subscription revenue belongs to the platform (not the store), so unlike the
 * store-level PaymentService this always uses the platform key. Kept tiny so
 * feature tests can swap this for a fake.StripeClient instance.
 */
class Stripe
{
    public function client(): StripeClient
    {
        return new StripeClient((string) config('payment.stripe.secret_key'));
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function createCheckoutSession(array $params): string
    {
        $session = $this->client()->checkout->sessions->create($params);

        return (string) $session->url;
    }

    /**
     * @return string The provider customer id.
     */
    public function createCustomer(string $email, ?string $name = null): string
    {
        $customer = $this->client()->customers->create(array_filter([
            'email' => $email,
            'name' => $name,
        ]));

        return (string) $customer->id;
    }

    /**
     * @return string The billing portal URL to send the user to.
     */
    public function createBillingPortalSession(string $customerId, string $returnUrl): string
    {
        $session = $this->client()->billingPortal->sessions->create([
            'customer' => $customerId,
            'return_url' => $returnUrl,
        ]);

        return (string) $session->url;
    }
}

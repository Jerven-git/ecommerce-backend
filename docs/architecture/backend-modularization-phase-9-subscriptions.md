# Backend modularization: Phase 9 subscriptions and platform billing

## Outcome

Phase 9 establishes an application boundary for platform subscriptions. Public plan discovery, tenant subscription status, Stripe Checkout and Billing Portal orchestration, webhook-driven lifecycle transitions, scheduled expiry, and Super Admin comp access now live outside controllers and console commands.

Routes, middleware ordering, HTTP response envelopes, Stripe payloads, subscription statuses, grace-window behavior, storefront visibility, and tenant-level authorization remain unchanged.

## Subscription boundary

`App\Modules\Subscriptions\Application` contains:

- `SubscriptionQuery` for the active plan catalog, tenant subscription projection, active-store resolution, and provider-customer lookup.
- `SubscriptionBilling` for customer reuse/creation, checkout line items, redirect URLs, and Billing Portal sessions.
- `SubscriptionLifecycle` for activation, renewal, cancellation, expiry, comp, and uncomp ledger transitions.
- `StripeWebhookProcessor` for event deduplication, store resolution, provider event mapping, and failure logging.
- `SubscriptionExpiry` for applying the configured grace window to elapsed paid periods.
- `SubscriptionOperationException` for preserving established application error status and context at the HTTP boundary.

The Stripe SDK wrapper remains under `App\Support\Subscriptions` as an infrastructure adapter. Signature verification remains in the webhook controller because it consumes the raw HTTP body and header. Subscription gating remains middleware because it is an access-policy boundary.

## Contracts preserved

- Public plan results still expose only active plans, ordered by price, with the same fields.
- Gated administrators can still read status and initiate checkout without entering subscribed routes.
- Existing Stripe customers are reused from the tenant's subscription ledger; new customer IDs are memoized before checkout.
- Checkout still combines a one-time setup fee with the recurring plan and uses the established success/cancel URLs.
- Portal access still requires an active tenant subscription and a provider customer ID.
- Stripe webhook signatures are verified before processing, event IDs remain deduplicated, and processing failures still return `200 OK` after structured logging.
- Initial activation, non-shrinking renewal windows, cancellation access, configured grace periods, expiry, and store-level gating are unchanged.
- Super Admin comp remains idempotent and uncomp returns only comped stores to the unsubscribed state.

## Correctness repair

The previous `invoice.payment_failed` handler referenced an undefined store variable. Stripe therefore received `200 OK`, but the ledger never recorded `payment_failed_at`. The processor now resolves the store by provider subscription/customer ID before updating the matching ledger row. A regression test verifies that failure metadata is recorded while access remains active through the grace window.

## Deliberate exclusions

Order-payment gateways and tenant payment settings remain in their Phase 3 and Phase 6 boundaries. Dashboard analytics, Admin Assistant integration, SiteConfig/Website Editor internals, frontend code, and infrastructure are unchanged.

## Validation

Focused coverage includes public plans, subscription status, checkout payloads, customer memoization, webhook signatures and deduplication, activation, renewal, failed invoices, cancellation, expiry and grace periods, tenant gating, storefront visibility, readiness, and Super Admin comp/uncomp behavior.

```bash
php artisan test \
  tests/Feature/SubscriptionFlowApiTest.php \
  tests/Feature/SubscriptionGatingApiTest.php \
  tests/Feature/SubscriptionStatusTest.php \
  tests/Feature/SuperAdmin/StoreCompTest.php \
  tests/Feature/SuperAdmin/StoreCrudTest.php \
  tests/Feature/SuperAdmin/StoreReadinessTest.php
```

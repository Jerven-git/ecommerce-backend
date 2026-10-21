# Backend Modularization Phase 3: Store-Order Payments

## Scope

Phase 3 moves store-order payment orchestration out of API controllers while preserving the routes and response contracts locked in Phase 1. It covers payment initiation, status polling, PayPal capture/return handling, provider webhook verification, normalization, deduplication, and tenant-scoped payment resolution.

Platform subscription billing remains deferred to Phase 9. Payment Settings and credential-management endpoints remain deferred to Phase 6. SiteConfig and Website Editor are unchanged.

## Application boundary

The `App\Modules\Payments\Application` layer now owns:

- `PaymentFlow`: redirect and Stripe initiation, opaque payment access grants, and provider status polling.
- `PayPalCapture`: tenant pinning, PayPal capture requests, audit metadata, and payment finalization.
- `WebhookVerifier`: provider-specific Stripe, Square, and PayPal signature verification.
- `WebhookReceiver`: tenant resolution, gateway normalization, event deduplication, payment lookup, metadata merging, and status application.
- Small result objects that keep HTTP response construction in controllers.

The existing gateways, credential resolver, opaque-token access service, and `PaymentService` remain stable infrastructure/domain collaborators. The three payment controllers are now HTTP adapters and keep their existing route signatures and response shapes.

## Contract and containment decisions

- Redirect initiation still returns `payment_id`, `payment_token`, `provider`, `provider_ref`, and `redirect_url` at the response root.
- Stripe initiation still returns `payment_id`, `payment_token`, and `client_secret` at the response root.
- Payment polling still requires an opaque token or an authorized tenant administrator and returns the existing flat status response.
- PayPal API capture and browser return/cancel flows retain their existing JSON and redirect behavior.
- Webhook verification continues to resolve per-store secrets only after the webhook URL has pinned the tenant.
- Webhook payment lookup is now constrained through the resolved order store, preventing a signed event for one merchant from updating another merchant's payment when provider references collide.
- Provider events remain idempotent through the existing unique `(provider, event_id)` record.
- Square's existing webhook parser is now declared through `HandlesWebhooks`, making the advertised Square webhook route operational.

## Validation

Focused contract coverage includes:

- PayPal and Stripe initiation payloads and persistence.
- Polling-driven payment confirmation and metadata merging.
- PayPal capture and redirect contracts.
- Stripe webhook deduplication.
- Square webhook normalization and confirmation.
- Cross-store webhook payment isolation.
- Existing opaque-token, PayPal credential scoping, and payment-confirmation tenant tests.

Run the focused Phase 3 suite with:

```bash
php artisan test \
  tests/Feature/Contracts/PaymentFlowContractTest.php \
  tests/Feature/Tenancy/PerStoreWebhookTest.php \
  tests/Feature/CriticalBackendContainmentTest.php \
  tests/Feature/Tenancy/PaymentConfirmationScopingTest.php \
  tests/Feature/Tenancy/PayPalTokenScopingTest.php
```

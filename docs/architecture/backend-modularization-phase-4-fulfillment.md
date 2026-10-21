# Backend modularization: Phase 4 backorders and shipments

## Outcome

Phase 4 moves backorder and shipment workflows out of HTTP controllers and into explicit application modules. Existing routes, middleware, validation rules, response envelopes, mail behavior, payment integration, stock timing, tracking output, and tenant scopes remain in place.

`BackorderController` and `ShipmentController` are now HTTP adapters. The payment-confirmation listener and backorder-expiry command also delegate to the same backorder lifecycle service instead of maintaining separate mutation paths.

## Backorder application boundary

- `BackorderQuery` owns tenant-scoped administrative queries and public-token lookup.
- `BackorderPricing` coordinates the existing tax and shipping calculators for the outstanding line.
- `BackorderCheckout` owns token validation, checkout summaries, provider payment initiation, and confirmation without online payment.
- `BackorderLifecycle` owns notification, stock reservation and release, cancellation, manual settlement, provider-paid fulfillment, and token expiry.
- `BackorderSettings` retains access to the existing tenant-owned operational settings.
- `BackorderOperationException` carries expected application failures without coupling services to JSON responses.

The public token lookup now requires the backorder's order to belong to the storefront tenant resolved by middleware. A token from another store therefore fails closed with `404` instead of loading relations outside the active tenant and producing a server error.

## Shipment application boundary

- `ShipmentLifecycle` owns shipment creation, duplicate detection, status changes, and parent-order transitions.
- `ShipmentTracking` owns tenant-scoped tracking lookup and barcode generation.
- `ShipmentOperationException` preserves the duplicate-shipment response data and status.

## Preserved contracts

- Backorder list pagination and root-level status counts are unchanged.
- Notification and resend continue to reserve stock transactionally and issue new opaque tokens.
- Cancellation and expiry release reserved stock once.
- Stripe, PayPal, and Square backorder payments retain their existing response keys and feed the Phase 3 payment services.
- Provider payment confirmation remains idempotent and advances the parent order when all backorders are settled.
- Shipment creation and update retain their existing JSON envelopes and order status transitions.
- Tracking responses remain tenant scoped; barcode responses remain SVG with a one-day public cache policy.

## Direct dependencies retained

This phase does not refactor SiteConfig or Website Editor. It retains only the backorder fields directly required by this workflow: enablement, payment-link expiry, and the administrative notification email. Broader operational-settings ownership remains deferred to Phase 6.

## Validation

Focused coverage includes administrative lifecycle operations, token validation, cross-store token containment, provider payment initiation and fulfillment, stock reservation/release, expiry processing, tracking, barcode output, Phase 1 route/response contracts, and critical tenant containment.

```bash
php artisan test \
  tests/Feature/BackorderApiTest.php \
  tests/Feature/ShipmentApiTest.php \
  tests/Feature/Contracts/CommerceResponseContractTest.php \
  tests/Feature/Contracts/CommerceRouteContractTest.php \
  tests/Feature/CriticalBackendContainmentTest.php
```

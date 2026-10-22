# Backend modularization: Phase 12 cross-cutting hardening

## Outcome

Phase 12 hardens shared runtime behavior across the extracted backend modules without changing successful API response envelopes or tenant resolution. The phase fixes tenant cache isolation, adds mutation-driven cache invalidation, makes webhook deduplication retry-safe, establishes bounded database concurrency retries, and locks representative read query budgets.

## Tenant-aware caching

`StoreCommerceCache` owns the cache keys and TTLs for order status counts and dashboard aggregates. Every key now includes the store ID. The former global `order_status_counts` key could return one tenant's counts to another tenant and has been removed.

Order, payment, and product model observers invalidate only the affected store's order and dashboard keys after mutations. The cache implementation does not require tag support, so it remains compatible with the configured Redis store and the array store used by tests.

## Retry-safe webhook processing

Payment and subscription webhook ledgers now distinguish receipt from successful processing:

- `attempts` counts processing attempts;
- `processed_at` makes successful handling idempotent;
- `failed_at` identifies the latest failed attempt; and
- `last_error` stores only the exception class, avoiding raw exception text or payload data in diagnostics.

`WebhookEventProcessor` serializes attempts with a database row lock. Application changes run inside a nested transaction: a failed application attempt rolls back its domain writes, while the failure state is committed and remains eligible for a later provider retry. A successful retry clears failure state and subsequent duplicate deliveries are acknowledged without applying the event again.

The forward-only migration marks existing ledger rows as processed because the previous schema treated row existence as completion. This prevents historical provider events from being replayed after deployment.

Subscription processing failures now return HTTP 500 so Stripe can retry. Signature failures remain 400, successful and already-processed events remain 200, and payment webhook failure behavior remains non-successful. Structured logs include provider, stream, store ID when known, event identifiers, attempt number, and exception class; they exclude webhook payloads and exception messages.

## Transaction and query policy

`Transaction::run(...)` standardizes three attempts for concurrency failures. Laravel retries only recognized concurrency errors; validation and domain exceptions still fail immediately. Order checkout, order lifecycle, and backorder inventory transitions now use this policy around their existing transaction and row-lock boundaries.

Focused feature coverage establishes cold-cache ceilings of 12 queries for the authenticated order index and 18 queries for dashboard stats on the isolated SQLite test database. These budgets protect query shape and regression risk, not production latency.

## Deliberate exclusions

No SiteConfig or Website Editor controllers, services, models, schema, routes, caching, or publishing flows were changed. Admin Assistant, frontend code, and infrastructure are unchanged. Phase 13 owns release-readiness checks and deployment guidance.

## Validation

```bash
php artisan migrate:fresh --seed
php artisan test tests/Feature/CrossCuttingHardeningTest.php
php artisan test tests/Feature/Contracts tests/Feature/Tenancy tests/Feature/SuperAdmin
php artisan test
```

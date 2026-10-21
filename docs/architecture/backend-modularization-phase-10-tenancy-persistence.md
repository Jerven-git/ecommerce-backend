# Backend modularization: Phase 10 tenancy and persistence boundaries

## Outcome

Phase 10 hardens the shared tenant context and persistence contract without changing API routes or response envelopes. Tenant context is now scoped to one request or queued job, each resolver starts from an explicit state, implicit tenant model binding fails closed, and a persisted model cannot be reassigned to another store.

No historical migration was consolidated or rewritten. Runtime schema characterization confirms that every direct tenant table already has a required `store_id`, a foreign key to `stores`, and cascading deletion, so an additional production migration would add risk without adding a missing guarantee.

## Context lifetime and resolution

- `CurrentStore` is container-scoped instead of process-singleton, preventing long-lived HTTP or queue workers from carrying a tenant into the next unit of work.
- Storefront resolution clears prior context before evaluating the current host.
- Authenticated resolution clears prior context before using `user.store_id`.
- Legacy store administrators without an assigned store resolve the configured default store explicitly. Super Administrators and ordinary users without a store remain tenantless.
- Host-agnostic payment webhooks clear prior context before resolving their explicit store slug or default-store fallback.
- Guest-facing routes retain the storefront context because authenticated resolution remains a no-op for guests.

## Model ownership

The shared `BelongsToStore` concern now provides:

- automatic `store_id` assignment from `CurrentStore` on creation;
- immutable tenant ownership after persistence;
- fail-closed implicit route binding when no tenant context exists;
- an explicit `forStore(...)` query scope for control-plane, reconciliation, and background work that cannot depend on ambient request state;
- an `isOwnedBy(...)` predicate for application-level ownership checks.

Super Admin control-plane routes continue binding platform `Store` and `User` records directly. Tenant records cannot be implicitly selected merely because a privileged user has no tenant context.

## Persistence guarantees

Characterization coverage verifies the shared ownership concern across these direct tenant model groups: catalog, orders, editorial content, engagement, gift cards, and commerce settings.

The migrated schema is also checked for a non-null cascading `stores.id` foreign key on:

- `categories`, `products`, `orders`, and `discounts`;
- `posts`, `post_categories`, `services`, and `service_categories`;
- `commission_requests` and `subscribers`;
- `gift_cards` and `gift_card_denominations`;
- `shipping_settings`, `shipping_zones`, `tax_settings`, `tax_rules`, and `payment_settings`.

Existing per-store unique indexes remain unchanged. Child records that inherit ownership through an order or product continue using the application-level parent checks and foreign keys established in earlier commerce phases.

## Deliberate exclusions

Phase 11 owns API route-file composition and module wiring. Phase 12 owns broader query budgets, cache invalidation, transaction/concurrency policy, retries, and logging. Dashboard analytics, Admin Assistant integration, frontend code, infrastructure, and SiteConfig/Website Editor internals are unchanged.

The shared tenancy concern is also used by SiteConfig document models, but this phase does not modify their services, controllers, schema, drafts, publishing, or editor behavior.

## Validation

Focused coverage includes context lifecycle replacement, direct model/schema contracts, default-store legacy resolution, fail-closed binding, authenticated tenant binding, ownership immutability, explicit store queries, host routing, catalog/settings isolation, webhook isolation, opaque payment access, subscription gating, and Super Admin separation.

```bash
php artisan test \
  tests/Feature/Tenancy \
  tests/Feature/CriticalBackendContainmentTest.php \
  tests/Feature/CommissionRequestApiTest.php \
  tests/Feature/CurrencyApiTest.php \
  tests/Feature/ProductApiTest.php \
  tests/Feature/ProductVariantApiTest.php \
  tests/Feature/SubscriptionGatingApiTest.php \
  tests/Feature/Contracts/CommerceSettingsContractTest.php \
  tests/Feature/SuperAdmin
```

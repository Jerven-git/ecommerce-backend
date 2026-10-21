# Backend modularization: Phase 11 route composition and module wiring

## Outcome

The API route surface now has one explicit composition root and module-owned route fragments. The refactor changes source ownership only: the complete serialized Laravel route table is identical to the pre-phase baseline, including methods, URIs, controller actions, middleware order, parameter constraints, and names.

`routes/api.php` remains responsible for global order and the shared access tiers:

- unversioned password recovery;
- host-agnostic v1 routes;
- storefront plus tenant resolution;
- provider webhooks;
- authenticated account and tenant resolution;
- admin authorization; and
- subscribed-admin authorization.

The composition root requires route fragments from Identity, ControlPlane, Subscriptions, Catalog, CommerceSettings, Editorial, Engagement, Orders, Payments, Backorders, Shipments, and Media. Realtime retains its existing provider-owned route registration.

## Contract lock

The pre-change and post-change `php artisan route:list --json` output has the same SHA-256 fingerprint and the same 198 routes. Of those, 189 are v1 routes and 24 belong to SiteConfig.

`ModuleRouteCompositionTest` checks that every declared fragment exists and is wired by the composition root. It also locks all 168 non-SiteConfig API routes with a fingerprint over Laravel's method, URI, action, middleware, constraint, and name output, and rejects duplicate method-plus-URI signatures.

An intentional API change must update the relevant behavioral contract first and then refresh this fingerprint as an explicit contract decision.

## Preserved boundaries

- Storefront routes still resolve the host store before authenticated tenant override and implicit binding.
- Provider webhooks remain host-agnostic.
- Subscription recovery routes remain outside the `subscribed` gate.
- Order, shipment, and backorder management remain authenticated and admin-only without gaining the subscription gate.
- Tenant content and settings mutations remain authenticated, admin-only, and subscription-gated.
- Super Admin routes retain their separate authorization boundary and literal domain-availability ordering.

## Deliberate exclusions

SiteConfig and Website Editor controller code, services, models, schema, and route definitions were not extracted. Their 24 routes remain inline and unchanged in the API composition root. Dashboard analytics and Admin Assistant were not moved into domain modules. Frontend and infrastructure files are unchanged.

Phase 12 owns broader cross-cutting hardening. Phase 13 owns release readiness.

## Validation

```bash
php artisan route:list --json
php artisan route:cache
php artisan route:clear
php artisan test tests/Feature/Contracts tests/Feature/Tenancy tests/Feature/SuperAdmin
php artisan test
```

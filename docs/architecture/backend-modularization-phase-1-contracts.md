# Backend modularization: Phase 1 contract baseline

## Scope

Phase 1 creates a safety net before production code is moved into modules. The
first bounded seam is commerce and fulfilment: orders, payments, backorders,
and shipments.

This area is the initial seam because it contains the two largest non-Website
Editor controllers and coordinates stock, totals, payment access, order state,
and fulfilment. SiteConfig and Website Editor behavior is outside this phase.
The existing currency/settings lookup used by checkout remains an unchanged
dependency.

## Locked contracts

`CommerceRouteContractTest` locks the existing HTTP methods and paths for the
commerce seam. It also locks the security boundary that future route files must
preserve:

- Storefront tenant resolution runs before checkout/payment/backorder/tracking.
- Authenticated management routes retain authentication, account-state,
  tenant-resolution, and admin authorization middleware.
- Existing named throttles remain attached to public sensitive endpoints.

`CommerceResponseContractTest` characterizes representative external payloads:

- Successful order creation retains the `message` plus `data.order` and
  `data.breakdown` envelope.
- Validation failures retain Laravel's `message` plus field-keyed `errors`
  envelope.
- Order management retains root-level pagination and `status_counts`.
- Payment polling retains its flat response and opaque-token access contract.
- Public tracking retains shipment data while exposing only the restricted
  nested order projection.
- Backorder token verification retains the checkout summary envelope.

The tests intentionally assert stable keys and security-sensitive omissions,
not timestamps, generated tokens, URLs, or other incidental values.

## Non-goals

- No controllers, models, services, repositories, routes, migrations, or
  frontend files are changed in this phase.
- No SiteConfig or Website Editor contract is re-baselined.
- No module namespace or target directory is selected yet.

## Exit criteria

Phase 1 is complete when the new contract tests, the existing focused commerce
tests, and the full backend suite pass. Production extraction should begin only
after approval of this baseline.

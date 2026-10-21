# Backend modularization: Phase 2 order application layer

## Outcome

Phase 2 moves order checkout and lifecycle behavior out of the HTTP controller
and into `App\Modules\Orders\Application`. The public and authenticated API
routes, middleware, status codes, response envelopes, tenant scopes, and stock
timing remain unchanged.

`OrderController` is now an HTTP adapter responsible for request validation,
response formatting, listing, and detail reads. It delegates mutations to the
order application layer.

## Application components

- `OrderCheckout` owns the checkout transaction, order persistence, order-item
  snapshots, backorder creation, gift-card checkout, and redemption.
- `OrderCartBuilder` locks the relevant product and variant rows, validates the
  requested catalog selections, and creates the item/tax/backorder snapshot.
  It does not deduct stock; the existing post-payment stock workflow remains
  authoritative.
- `CartSnapshot` gives checkout a named boundary instead of a positional tuple.
- `OrderPricing` coordinates shipping, regional tax, and discount calculations.
- `OrderLifecycle` owns status transitions, manual payment confirmation and
  reversal, cancellation/refund handling, stock restoration, deletion rules,
  and cancellation mail.
- `OrderOperationException` carries application failure status without coupling
  the application services to JSON responses.

Laravel can resolve these concrete services through the container, so this
phase does not require a new provider or route registration.

## Preserved boundaries

- The Phase 1 route and response contract tests remain the external authority.
- Product and variant rows are locked in checkout as before.
- Checkout remains atomic, including discounts, gift-card redemption, order
  items, and backorders.
- Stock remains deducted by the existing payment-confirmation listeners and is
  restored through `OrderRepository`.
- The order's tenant continues to come from `CurrentStore` and existing model
  scopes.
- The existing read-only SiteConfig currency lookup is retained as the one
  direct dependency allowed by the agreed scope.

## Deliberately deferred

- Payment gateways and webhooks belong to Phase 3.
- Backorder and shipment module extraction belongs to Phase 4.
- Catalog models and variant administration belong to Phase 5.
- Shipping, tax, discount, gift-card, and currency ownership is revisited in
  Phase 6; Phase 2 only moves their checkout coordination.
- Order query extraction and route composition remain deferred until the wider
  module boundaries are established.

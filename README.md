# SSU Backend

Laravel API backend for SSU — handles orders, payments, shipping, and admin management.

## Payment System

### How It Works

The payment system uses an **event-driven architecture**. The core flow is:

```
Customer places order
  → Controller creates a pending Payment
  → Customer pays (Stripe / PayPal / Square / Cash)
  → PaymentService::markPaid() is called
  → PaymentConfirmed event is dispatched
  → Listeners handle side effects independently
```

### Gateway Architecture

The system supports multiple payment providers through a **gateway pattern**. Each provider is a class that implements one or more contracts:

| Contract | Method | Purpose |
|----------|--------|---------|
| `PaymentGateway` | `createPayment()` | Required. Creates a payment on the provider's side and returns data the frontend needs (client_secret, redirect URL, etc.) |
| `HandlesWebhooks` | `parseWebhook()` | Optional. Normalizes incoming webhook payloads into a standard format |
| `ChecksPaymentStatus` | `verifyPayment()` | Optional. Polls the provider's API to check if a payment succeeded or failed |

Each gateway implements the contracts relevant to its provider:

| Gateway | `PaymentGateway` | `HandlesWebhooks` | `ChecksPaymentStatus` |
|---------|:-:|:-:|:-:|
| `StripeGateway` | yes | yes | yes |
| `PayPalGateway` | yes | yes | no |
| `SquareGateway` | yes | no | yes |

**GatewayManager** is the registry. It's registered as a singleton in `AppServiceProvider` and holds all gateway instances. Controllers never instantiate gateways directly — they call `$manager->get('stripe')` to resolve the correct one by name.

```php
// How controllers use it
$gateway = $manager->get('stripe');       // returns StripeGateway
$init = $gateway->createPayment($order);  // creates PaymentIntent on Stripe
```

**Adding a new provider** (e.g., Authorize.net):
1. Create `app/Payments/Gateways/AuthorizeGateway.php` implementing `PaymentGateway` (and optionally `HandlesWebhooks` / `ChecksPaymentStatus`)
2. Register it in `AppServiceProvider` inside the `GatewayManager` constructor
3. Add the provider name to the validation rules in the relevant controller

No existing gateway code needs to change.

### Payment Flow (Step by Step)

#### 1. Order Creation
**Route:** `POST /api/orders`
**Controller:** `OrderController::store()`

The customer submits their order. For cash payments, a pending `Payment` record is created immediately via `PaymentService::createPending()`.

#### 2. Payment Initiation
**Route:** `POST /api/orders/{order}/pay`
**Controller:** `PaymentController::pay()`

For redirect-based payments (PayPal, Square), the frontend calls this endpoint. It resolves the correct gateway via `GatewayManager`, creates a pending `Payment` record, and returns a `redirect_url` for the customer to complete checkout on the provider's site.

**Note:** Stripe is not accepted on this endpoint — it has its own dedicated flow below.

#### 2b. Stripe Payment Intent
**Route:** `POST /api/orders/{order}/stripe/intent`
**Controller:** `PaymentController::stripeIntent()`

Stripe uses a card form rendered on your frontend via Stripe.js, not a redirect. This endpoint creates a Stripe PaymentIntent via `StripeGateway::createPayment()`, saves a pending `Payment` record, and returns a `client_secret` that the frontend uses to render the card form and handle 3D Secure authentication.

#### 3. Payment Confirmation
Payment gets confirmed through one of these paths:

| Path | Route | When |
|------|-------|------|
| **Webhook** | `POST /api/webhooks/{provider}` | Stripe/Square sends async notification |
| **PayPal Capture** | `POST /api/paypal/capture` | Frontend captures after PayPal approval |
| **PayPal Return** | `GET /api/paypal/return` | PayPal redirects back after approval |
| **Status Check** | `GET /api/payments/{payment}` | Frontend polls; if provider says paid, we mark it |
| **Cash Confirm** | `POST /api/orders/{id}/confirm-payment` | Admin manually confirms cash payment |

All paths end up calling `PaymentService::markPaid()`.

#### 3b. Payment Failure Detection
Failed/incomplete payments are detected through three layers:

| Layer | How | When |
|-------|-----|------|
| **Webhook** | Provider sends failure event (e.g., `payment_intent.payment_failed`) | Real-time (requires Cloudflare tunnel) |
| **Status Polling** | `GET /api/payments/{payment}` checks provider and marks `failed` | When frontend polls |
| **Scheduled Cleanup** | `payments:expire-stale` command runs every 15 minutes | Catches abandoned payments (customer closed browser, never returned) |

Payments older than 30 minutes that are still `pending` are marked `expired`, and their associated orders are `cancelled`.

#### 4. What Happens After markPaid()

`markPaid()` does two things:
1. Updates the payment status to `paid`
2. Dispatches the `PaymentConfirmed` event

The event triggers these **listeners** (registered in `AppServiceProvider`):

| Listener | What It Does |
|----------|-------------|
| `UpdateOrderStatus` | Changes order status from `pending` → `processing` |
| `DeductStock` | Validates inventory and decrements product stock. If stock is insufficient, flags the order as `failed_needs_refund` and dispatches `OrderRequiresRefund` |
| `SendOrderConfirmation` | Sends an order confirmation email to the customer with itemized receipt (subtotal, discount, tax, shipping, total) |
| `HandleFailedOrder` | Logs the failure for admin review (placeholder for automated refunds and notifications) |

### Payment Statuses

| Status | Meaning |
|--------|---------|
| `pending` | Payment created, waiting for provider confirmation |
| `paid` | Payment confirmed by provider |
| `failed` | Payment declined or cancelled by provider |
| `expired` | Payment abandoned — no response within 30 minutes (set by scheduler) |
| `refunded` | Order cancelled after payment — admin must process refund through payment provider |

### File Reference

```
app/Payments/
├── PaymentService.php                  # Core service — creates payments, marks paid, dispatches events
├── GatewayManager.php                  # Registry that resolves provider name → gateway instance
├── InsufficientStockException.php      # Thrown when stock is too low during deduction
├── PayPalToken.php                     # Handles PayPal OAuth token management
├── VerifySquareSignature.php           # Middleware to verify Square webhook signatures
├── Contracts/
│   ├── PaymentServiceInterface.php     # Interface for PaymentService (swappable in tests)
│   ├── PaymentGateway.php              # Interface all gateways implement
│   ├── HandlesWebhooks.php             # Interface for gateways that receive webhooks
│   └── ChecksPaymentStatus.php         # Interface for gateways that support status polling
├── DTOs/
│   └── PaymentInitData.php             # Typed data object for payment initialization params
└── Gateways/
    ├── StripeGateway.php               # Stripe integration
    ├── PayPalGateway.php               # PayPal integration
    └── SquareGateway.php               # Square integration

app/Events/
├── PaymentConfirmed.php                # Dispatched when a payment is marked as paid
└── OrderRequiresRefund.php             # Dispatched when stock deduction fails after payment

app/Listeners/
├── UpdateOrderStatus.php              # Sets order to "processing" after payment
├── DeductStock.php                    # Validates and decrements product inventory
├── SendOrderConfirmation.php          # Sends order confirmation email to customer
└── HandleFailedOrder.php              # Handles orders that need refunds

app/Http/Controllers/Api/
├── OrderController.php                # Order CRUD + cash payment confirmation
├── PaymentController.php              # Stripe intent, PayPal/Square pay, status checks
├── PayPalReturnController.php         # PayPal redirect/capture handling
└── WebhookController.php              # Incoming webhooks from Stripe/PayPal/Square

app/Console/Commands/
└── ExpireStalePendingPayments.php     # Scheduled: expires stale pending payments + cancels orders

scripts/
└── setup-cron.sh                      # One-time setup: adds Laravel scheduler to crontab
```

### Key Design Decisions

- **Event-driven side effects** — `PaymentService` doesn't handle stock or order status directly. It dispatches `PaymentConfirmed` and listeners handle the rest. This means adding new behavior (email notifications, audit logs) requires zero changes to existing code.

- **Interface binding** — `PaymentServiceInterface` is bound to `PaymentService` in `AppServiceProvider`. Type-hint the interface in controllers/tests to swap implementations.

- **Idempotent stock deduction** — `DeductStock` checks `order.stock_deducted_at` before deducting. Safe to call multiple times (e.g., duplicate webhooks).

- **Deadlock prevention** — Products are locked in ascending ID order during stock deduction to prevent database deadlocks when multiple orders process concurrently.

- **Payment before stock** — The payment is marked `paid` outside the stock transaction. If stock deduction fails, the order is flagged `failed_needs_refund` rather than rolling back the payment (which was already captured by the provider).

### Order Statuses

| Status | Meaning |
|--------|---------|
| `pending` | Order created, awaiting payment |
| `processing` | Payment confirmed, stock deducted |
| `shipped` | Order has been shipped |
| `delivered` | Order received by customer |
| `cancelled` | Order was cancelled or payment expired (abandoned) |
| `failed_needs_refund` | Payment succeeded but stock deduction failed — needs manual refund |

### Order Status Transitions

Status changes are enforced by a state machine in `OrderController::updateStatus()`. Not every transition is allowed:

```
pending → processing → shipped → delivered
  ↓           ↓
cancelled  cancelled
```

- `delivered` and `cancelled` are **terminal states** — no further transitions allowed.
- **Cancelling restores stock** — if `stock_deducted_at` is set, all item quantities are incremented back on the product. The `stock_deducted_at` timestamp is cleared.
- **Cancelling fails pending payments** — if the order has a pending payment, it's marked `failed`.
- **Cancel & Refund** — for orders with a completed payment (`paid`), use `POST /orders/{id}/cancel-refund`. This cancels the order, restores stock, marks the payment as `refunded`, and sends a cancellation email. The admin must then process the actual refund through their payment provider (Stripe/PayPal/Square dashboard).
- **Deletion is restricted** — only `pending` or `cancelled` orders can be deleted, and only if stock hasn't been deducted (`stock_deducted_at` is null).

### Scheduled Tasks

Defined in `routes/console.php`:

| Task | Frequency | Purpose |
|------|-----------|---------|
| `payments:expire-stale --minutes=30` | Every 15 minutes | Expires abandoned pending payments, cancels their orders |
| Two-factor code cleanup | Hourly | Deletes expired 2FA codes |

Setup cron automatically:
```bash
bash scripts/setup-cron.sh
```

Or manually add this cron entry:
```
* * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
```

## Backorder System

### How It Works

The backorder system allows customers to purchase products that are currently out of stock. It is controlled by a **global master switch** (`backorder_enabled` in `site_config`) and a **per-product toggle** (`allow_backorder`) — both must be enabled for a product to accept backorders.

### Charge Policies

Each backorder-enabled product has a charge policy that determines when the customer pays:

| Policy | Behavior |
|--------|---------|
| `charged_now` | Customer pays for backorder items at checkout (included in order total) |
| `charged_later` | Customer pays only when stock arrives and admin sends a payment link |

### Backorder Flow (Step by Step)

#### 1. Order Creation with Backorder Items
**Route:** `POST /api/orders`
**Controller:** `OrderController::store()`

During checkout, `buildCartAndReserveStock()` separates cart items into **in-stock** and **backorder** buckets:
- **In-stock items:** Added to `order_items`, stock reserved, included in subtotal
- **Backorder items:** Added to `order_items` with "(Backorder)" suffix, and separate `Backorder` records are created with status `awaiting_stock`

For `charged_later`, the backorder amount is **excluded** from the checkout total. If all items are deferred backorders, no payment is required at checkout.

The order gets `has_backorder_items = true` and status `backorder_awaiting_stock`.

#### 2. Stock Deduction
**Listener:** `DeductStock`

When `PaymentConfirmed` fires, `DeductStock` skips backorder items — their stock is only deducted when the backorder itself is paid (handled by `FulfillBackorder`).

#### 3. Admin Sends Payment Link
**Route:** `POST /api/v1/backorders/{id}/notify`
**Controller:** `BackorderController::notify()`

When stock arrives, the admin sends a payment link:
1. Validates the backorder is in `awaiting_stock` status
2. Checks the product has sufficient stock
3. Generates a secure 64-character payment token
4. Sets token expiry (configurable via `backorder_payment_link_expiry_hours`, default 24h)
5. Updates status to `notified` and sets `notified_at`
6. Sends `BackorderPaymentLinkMail` to the customer

The link can be resent via `POST /api/v1/backorders/{id}/resend` (works for `notified` or `expired` backorders).

#### 4. Customer Pays
**Route:** `POST /api/v1/backorders/pay/{token}`
**Controller:** `BackorderController::payByToken()`

The customer accesses a public, token-based payment page (no login required):
1. Token and expiry are validated
2. **Stock is verified** — if the product is no longer available in sufficient quantity, payment is rejected with a 409 error
3. Backorder total is calculated (product price × quantity + tax + shipping)
4. Payment is processed through the selected gateway (Stripe, PayPal, or Square)
5. Payment metadata includes `backorder_id` for fulfillment routing

#### 5. Fulfillment
**Listener:** `FulfillBackorder`

On payment confirmation:
1. Backorder status → `paid`, sets `paid_at`, clears `payment_token`
2. **Product stock is deducted** for the backorder quantity
3. If all backorders on the parent order are `paid` or `cancelled`, order status transitions to `processing`

### Backorder Statuses

| Status | Meaning |
|--------|---------|
| `awaiting_stock` | Backorder created, waiting for stock to arrive |
| `notified` | Admin sent payment link, waiting for customer to pay |
| `expired` | Payment link expired (customer didn't pay in time) |
| `paid` | Customer paid, stock deducted |
| `cancelled` | Admin cancelled the backorder |

**Status Transitions:**
```
awaiting_stock → notified → paid
                    ↓
                 expired → (resend) → notified

Any non-paid status → cancelled
```

### Backorder API Endpoints

#### Admin (Authenticated)

| Method | Endpoint | Purpose |
|--------|----------|---------|
| `GET` | `/v1/backorders` | List backorders (filter by status, product_id, search) |
| `GET` | `/v1/backorders/{id}` | Backorder detail with relationships |
| `POST` | `/v1/backorders/{id}/notify` | Send payment link to customer |
| `POST` | `/v1/backorders/{id}/resend` | Resend payment link |
| `POST` | `/v1/backorders/{id}/cancel` | Cancel backorder and notify customer |
| `GET` | `/v1/backorder-settings` | Get global backorder settings |
| `PATCH` | `/v1/backorder-settings` | Update global backorder settings |

#### Public (Token-based, no auth)

| Method | Endpoint | Purpose |
|--------|----------|---------|
| `GET` | `/v1/backorders/pay/{token}` | Verify token and get backorder payment details |
| `POST` | `/v1/backorders/pay/{token}` | Process backorder payment |

### Email Notifications

| Mail Class | Template | Trigger |
|------------|----------|---------|
| `OrderConfirmationMail` | `emails/order-confirmation` | Payment confirmed (via `SendOrderConfirmation` listener) |
| `OrderCancellationMail` | `emails/order-cancellation` | Admin cancels an order |
| `BackorderPaymentLinkMail` | `emails/backorder-payment-link` | Admin sends/resends payment link (includes tax & shipping breakdown) |
| `BackorderCancellationMail` | `emails/backorder-cancellation` | Admin cancels a backorder |

### Stock Protection

- Uses **pessimistic locking** (`lockForUpdate` in DB transaction) to prevent race conditions
- Stock is only deducted when the backorder payment is confirmed, not when the link is sent
- **Payment-time stock check** — `payByToken()` verifies stock availability before accepting payment, returning 409 if insufficient. The `verifyToken()` endpoint also returns a `stock_available` flag so the frontend can warn the customer before they attempt to pay

### File Reference

```
app/Models/Backorder.php                          # Backorder model with scopes and token validation
app/Http/Controllers/Api/V1/BackorderController.php  # All backorder endpoints
app/Listeners/FulfillBackorder.php                 # Handles stock deduction + status update on payment
app/Mail/OrderConfirmationMail.php                    # Order confirmation email (sent on payment)
app/Mail/OrderCancellationMail.php                    # Order cancellation email (sent on cancel)
app/Mail/BackorderPaymentLinkMail.php              # Payment link email (with tax & shipping)
app/Mail/BackorderCancellationMail.php             # Cancellation email
app/Listeners/SendOrderConfirmation.php            # Listener that sends confirmation on PaymentConfirmed
resources/views/emails/order-confirmation.blade.php
resources/views/emails/backorder-payment-link.blade.php
resources/views/emails/backorder-cancellation.blade.php
database/migrations/2026_03_17_010002_create_backorders_table.php
```

## Future Improvements

### High Priority

- **Queueable listeners** — Add `implements ShouldQueue` to `DeductStock` and `UpdateOrderStatus`. Currently these run synchronously inside the webhook response. Under high load, stock deduction could timeout and the webhook would fail. Queuing makes the webhook respond instantly and processes side effects in the background.

- **Automated refunds** — `HandleFailedOrder` currently only logs. When a payment succeeds but stock deduction fails, the customer is charged with no product to ship. This listener should call the provider's refund API automatically (e.g., `Stripe::refunds()->create()`) and notify the admin.

### Medium Priority

- **Payment audit trail** — Log every status transition (pending → paid, pending → expired, paid → refunded) with timestamps in a `payment_events` table. Critical for handling customer disputes and chargebacks.

- **Webhook event storage** — Store raw webhook payloads in a `webhook_events` table. This lets you replay failed webhooks, debug provider issues, and provides an audit trail independent of your payment records.

- **Admin refund notification** — Add an admin email notification on `OrderRequiresRefund` so admins are alerted when a payment succeeds but stock deduction fails.

### Low Priority

- **Gateway retry logic** — If a provider's API is temporarily down (e.g., Stripe 503), queue the request and retry with exponential backoff instead of failing immediately.

- **Soft deletes on orders/payments** — Instead of keeping all records forever, add `SoftDeletes` and archive old completed orders to a separate table or export to cloud storage (S3) after 12 months.

- **Payment analytics dashboard** — Track conversion rates (orders created vs. paid), abandonment rates (expired payments), failure rates per provider, and average time to payment completion.

## API Routes Overview

All routes are prefixed with `/api`. See `routes/api.php` for the full definition.

### Public (no auth)

| Method | Endpoint | Purpose |
|--------|----------|---------|
| `POST` | `/login` | Admin login |
| `POST` | `/two-factor/verify` | 2FA verification |
| `POST` | `/two-factor/resend` | Resend 2FA code |
| `GET` | `/products` | List products |
| `GET` | `/products/{id}` | Product detail |
| `GET` | `/categories` | List categories |
| `GET` | `/site-config` | Storefront configuration |
| `POST` | `/contact` | Contact form |
| `GET` | `/discounts` | List active discounts |
| `POST` | `/discounts/validate` | Validate a discount code |
| `GET/POST` | `/shipping/*` | Shipping options, zones, calculate |
| `POST` | `/tax/calculate`, `/tax/calculate-cart` | Tax calculation |

### Checkout & Payment (no auth)

| Method | Endpoint | Purpose |
|--------|----------|---------|
| `POST` | `/orders` | Create an order |
| `POST` | `/orders/{order}/pay` | Initiate PayPal/Square payment |
| `POST` | `/orders/{order}/stripe/intent` | Create Stripe PaymentIntent |
| `GET` | `/payments/{payment}` | Check payment status (polls provider) |
| `GET` | `/paypal/return` | PayPal redirect return |
| `POST` | `/paypal/capture` | Capture PayPal payment |
| `POST` | `/webhooks/{provider}` | Incoming webhooks (stripe/paypal/square) |

### Authenticated (Sanctum)

| Method | Endpoint | Purpose |
|--------|----------|---------|
| `GET` | `/orders` | List all orders |
| `GET` | `/orders/{id}` | Order detail |
| `PATCH` | `/orders/{id}` | Update order status (state machine enforced) |
| `POST` | `/orders/{id}/confirm-payment` | Admin confirms cash payment |
| `POST` | `/orders/{id}/undo-payment` | Admin reverses a payment |
| `POST` | `/orders/{id}/cancel-refund` | Cancel paid order, restore stock, mark for refund |
| `DELETE` | `/orders/{id}` | Delete order (pending/cancelled only) |
| `POST` | `/orders/{id}/ship` | Create shipment |
| `PATCH` | `/shipments/{id}` | Update shipment |

### Admin Only (Sanctum + admin middleware)

CRUD for products, categories, discounts, site config, and settings (shipping, tax, payment).

## Setup

```bash
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate --seed
bash scripts/setup-cron.sh   # sets up the Laravel scheduler
```

## Environment Variables

Payment provider credentials are configured in `.env`. See `config/payment.php` for the full list of required keys for each provider (Stripe, PayPal, Square).

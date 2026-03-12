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
| `HandleFailedOrder` | Logs the failure for admin review (placeholder for automated refunds and notifications) |

### Payment Statuses

| Status | Meaning |
|--------|---------|
| `pending` | Payment created, waiting for provider confirmation |
| `paid` | Payment confirmed by provider |
| `failed` | Payment declined or cancelled by provider |
| `expired` | Payment abandoned — no response within 30 minutes (set by scheduler) |

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

## Future Improvements

### High Priority

- **Queueable listeners** — Add `implements ShouldQueue` to `DeductStock` and `UpdateOrderStatus`. Currently these run synchronously inside the webhook response. Under high load, stock deduction could timeout and the webhook would fail. Queuing makes the webhook respond instantly and processes side effects in the background.

- **Automated refunds** — `HandleFailedOrder` currently only logs. When a payment succeeds but stock deduction fails, the customer is charged with no product to ship. This listener should call the provider's refund API automatically (e.g., `Stripe::refunds()->create()`) and notify the admin.

### Medium Priority

- **Payment audit trail** — Log every status transition (pending → paid, pending → expired, paid → refunded) with timestamps in a `payment_events` table. Critical for handling customer disputes and chargebacks.

- **Webhook event storage** — Store raw webhook payloads in a `webhook_events` table. This lets you replay failed webhooks, debug provider issues, and provides an audit trail independent of your payment records.

- **Email notifications** — Add a `SendOrderConfirmation` listener on `PaymentConfirmed` to email the customer their receipt. Add an admin notification on `OrderRequiresRefund`.

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

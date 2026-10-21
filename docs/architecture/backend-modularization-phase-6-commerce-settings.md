# Backend modularization: Phase 6 commerce settings

## Outcome

Phase 6 moves operational commerce configuration and incentive orchestration out of API controllers and into `App\Modules\CommerceSettings\Application`. Shipping, tax, tax reporting, discounts, gift cards, currencies, and payment-method settings retain their existing routes and response contracts.

The affected controllers are now HTTP adapters: they validate requests, delegate work to the application boundary, and shape the existing JSON or CSV responses.

## Application boundary

- `ShippingConfiguration` owns tenant shipping settings and zones and exposes the existing shipping quote/options engine.
- `TaxConfiguration` owns tenant tax defaults, regional resolution, and cart tax calculations.
- `TaxRuleManager` owns regional tax-rule CRUD and transactional synchronization.
- `TaxReport` owns tenant order aggregation for JSON and CSV tax reports.
- `DiscountManager` owns tenant discount querying and CRUD; discount validity and arithmetic remain model behavior.
- `GiftCardManager` owns denominations, issued-card administration, public validation, and the existing gift-card module gate.
- `CurrencyManager` owns the intentionally global currency catalog and transactional base-currency promotion.
- `PaymentConfiguration` owns tenant payment toggles, credential updates, public-method projection, and provider webhook URLs.

Models remain the persistence and entity-behavior layer. The existing `ShippingCalculator` remains the shared quote engine consumed by order, backorder, mail, and settings application services.

## Tenant containment and secrets

- Shipping settings, shipping zones, tax settings, tax rules, discounts, gift cards, denominations, payment settings, reports, and public calculations continue to use the active store scope.
- Tax-rule synchronization now validates referenced rule IDs against the active store before mutation. Cross-store IDs receive the normal validation-error envelope.
- Currencies remain global by design and are not changed to tenant-owned records.
- Payment secrets remain encrypted and hidden. Blank credential fields continue to mean "leave the stored credential unchanged"; public payment-method responses expose only provider initialization identifiers.
- Gift-card availability still reads the current store's `SiteConfig.modules_enabled.gift_cards` flag. This is the only direct SiteConfig dependency in the phase and is retained because it is part of the existing public API contract.

## Preserved contracts

- No routes, middleware groups, request field names, success messages, pagination envelopes, CSV columns, or status codes were renamed.
- Shipping settings still return zones under `data.zones`; quotes retain their flat fee breakdown.
- Tax calculations retain their flat totals and regional metadata; tax reports remain grouped under `data`.
- Discount validation retains its `valid`, `discount`, `discount_amount`, and `final_amount` fields.
- Gift-card endpoints retain denomination and balance envelopes, and disabled storefront gift cards still return 404.
- Currency listing retains `data` plus the root `base` code.
- Payment settings retain `data` plus `webhook_urls`; public methods retain the provider-specific `config` objects.

## Scope exclusions

Payment gateway execution and webhook processing remain in the Phase 3 Payments module. Order and backorder pricing remain in their existing application modules and continue consuming the shared shipping and tax models. SiteConfig and Website Editor internals, frontend code, infrastructure, subscriptions, and platform billing are unchanged.

## Validation

Focused coverage includes response contracts for shipping quotes/settings, tax settings/rules/calculation/reporting, gift-card administration and validation, currencies, discounts, payment credential secrecy, webhook URLs, and tenant isolation.

```bash
php artisan test \
  tests/Feature/Contracts/CommerceSettingsContractTest.php \
  tests/Feature/DiscountApiTest.php \
  tests/Feature/CurrencyApiTest.php \
  tests/Feature/Tenancy/SettingsScopingTest.php \
  tests/Feature/Tenancy/PaymentSettingsAdminTest.php \
  tests/Feature/Tenancy/PaymentMethodsScopingTest.php \
  tests/Feature/Tenancy/PerStoreWebhookTest.php \
  tests/Feature/Tenancy/CatalogScopingTest.php \
  tests/Feature/Contracts/CommerceResponseContractTest.php
```

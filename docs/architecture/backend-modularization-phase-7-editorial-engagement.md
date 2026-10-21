# Backend modularization: Phase 7 editorial and engagement

## Outcome

Phase 7 moves tenant editorial content and customer-engagement workflows out of API controllers. Posts, services, their taxonomies, commission requests, contact delivery, and newsletter signup now delegate to application services while retaining their existing routes and response contracts.

## Application boundaries

`App\Modules\Editorial\Application` owns tenant storefront and administration behavior for editorial records:

- `EditorialQuery` owns published post/service discovery, filters, ordering, pagination, detail hydration, related records, and administrative listings.
- `PostManager` and `ServiceManager` own slugging, publication timestamps, cover media, persistence, ordering, and deletion behavior.
- `TaxonomyManager` owns post/service category queries, media, ordering, and deletion.

`App\Modules\Engagement\Application` owns public engagement workflows:

- `RecaptchaVerifier` centralizes the existing optional server verification behavior.
- `ContactDelivery` resolves configured recipients and sends contact mail.
- `CommissionManager` owns module gating, submission media, notifications, administrative querying, status updates, and deletion.
- `NewsletterSubscription` owns tenant-scoped subscriber idempotency and optional welcome-discount creation.

Controllers remain responsible for request validation and the existing HTTP envelopes.

## Tenant containment

- All editorial, taxonomy, commission, subscriber, and generated-discount queries retain the active store scope.
- Post and service category references are now validated against the active store.
- Service, post-category, and service-category reorder payloads are now validated against the active store before any ordering mutation.
- Existing route-model binding continues to fail closed for cross-store commission records.

## Preserved contracts

- Published post and service lists retain root pagination, while `limit` mode retains the `data` envelope.
- Detail endpoints retain `data` and `related`.
- Administrative create/update messages, media URLs, automatic slugs, publication timestamps, and category/media hydration are unchanged.
- Category lists retain plain `data` output by default and root pagination when `page` or `per_page` is supplied.
- Commission submissions retain their 201 message and opaque ID response; disabled public submissions still return 404 while administrative history remains accessible.
- Contact submission still uses configured contact entries before the fallback address and returns the existing success message.
- Newsletter signup retains 201 for a new subscriber, 200 for an existing subscriber, and the optional welcome discount code.

## Direct SiteConfig dependencies

SiteConfig and Website Editor internals are unchanged. Three read-only operational dependencies remain because they are required by existing public contracts:

- commission module enablement;
- contact notification recipients;
- the configured welcome-popup discount template.

## Scope exclusions

Dashboard analytics, authentication, administration/control-plane users, subscriptions/platform billing, frontend code, infrastructure, and all SiteConfig/Website Editor mutation and publishing workflows are unchanged.

## Validation

Focused coverage includes editorial response shapes, publication/admin behavior, tenant-scoped category references and reordering, commission submission and administration, contact recipient delivery, newsletter idempotency, welcome discounts, SEO, seeders, tenant isolation, and per-store slug behavior.

```bash
php artisan test \
  tests/Feature/Contracts/EditorialEngagementContractTest.php \
  tests/Feature/CommissionRequestApiTest.php \
  tests/Feature/PostCategoryApiTest.php \
  tests/Feature/ServiceCategoryApiTest.php \
  tests/Feature/SeoApiTest.php \
  tests/Feature/SeederTest.php \
  tests/Feature/Tenancy/CatalogScopingTest.php \
  tests/Feature/Tenancy/SlugUniquenessTest.php
```

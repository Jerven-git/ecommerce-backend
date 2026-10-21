# Backend modularization: Phase 5 catalog and variants

## Outcome

Phase 5 moves product, category-tree, and product-variant orchestration out of API controllers and into `App\Modules\Catalog\Application`. Public and administrative routes, response envelopes, validation behavior, media collections, tenant scopes, and order-facing variant data remain compatible.

The three catalog controllers are now HTTP adapters. Catalog validation is represented by dedicated form requests so tenant-owned category references are checked before application mutations begin.

## Application boundary

- `ProductQuery` owns storefront product listing, filtering, category-descendant lookup, pagination, and product detail hydration.
- `ProductManager` owns product creation, updates, category synchronization, primary images, hover images, galleries, and deletion.
- `CategoryQuery` owns the ordered recursive storefront category tree.
- `CategoryManager` owns category creation, hierarchy changes, cycle prevention, ordering, media, and deletion.
- `VariantManager` owns option/value synchronization, variant pivots, variant deletion through snapshot replacement, and variant images.
- `CatalogOperationException` preserves expected category and variant failure responses without coupling application services to JSON.

Product, category, option, value, and variant models remain the persistence and relationship layer. Existing slug generation and model normalization remain unchanged.

## Tenant containment

- Product `category_id` and `category_ids` validation now accepts only categories belonging to the active store.
- Category parent and reorder validation now accepts only categories belonging to the active store.
- Variant image upload and deletion now resolve the product through its tenant scope before resolving the child variant.
- Option, value, and variant synchronization continues to resolve every existing child through the selected product relationship and runs transactionally.

These checks prevent cross-store foreign keys and media mutation even though child option and variant tables do not carry their own `store_id` columns.

## Preserved contracts

- Product list filters, descendant-category matching, limit responses, pagination, sorting, and active-variant stock aggregates are unchanged.
- Product detail continues to expose options and active variants under `data`.
- Product and category media continue using the existing media service and public-disk URLs.
- Category trees retain recursive `children` output and the existing cycle error messages.
- Variant sync retains the `options`/`variants` response and one-value-per-option validation messages.
- Orders continue to snapshot variant SKU, price, selected options, and stock through the Phase 2 order application layer.

## Scope exclusions

SiteConfig, Website Editor, post categories, service categories, storefront UI, and infrastructure are unchanged. The existing read-only backorder capability accessor on `Product` remains a direct dependency because it is part of the product API contract; broader operational-settings ownership remains deferred to Phase 6.

## Validation

Focused coverage includes product CRUD and media, public product queries, category hierarchy and media, transactional variant synchronization, order variant snapshots and stock behavior, tenant catalog isolation, per-store slugs, SEO fields, and cross-store category/variant containment.

```bash
php artisan test \
  tests/Feature/ProductApiTest.php \
  tests/Feature/CategoryApiTest.php \
  tests/Feature/ProductVariantApiTest.php \
  tests/Feature/Tenancy/CatalogScopingTest.php \
  tests/Feature/Tenancy/SlugUniquenessTest.php \
  tests/Feature/SeoApiTest.php
```

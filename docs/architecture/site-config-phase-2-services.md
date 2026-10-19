# SiteConfig refactor: Phase 2 service boundaries

Status: completed internal refactor; no database, route, authorization, or response-shape changes.

Phase 2 reduces `SiteConfigController` from 1,241 lines to a 100-line HTTP coordinator. Tenant lookup, storefront presentation, direct-update validation, update normalization, module defaults, and media lifecycle behavior now have separate owners.

## Responsibilities

- `SiteConfigResolver` resolves authenticated requests by `user.store_id`, resolves public requests from the active storefront tenant, retains the development `?store=slug` compatibility path, and keeps the existing scoped fallback for legacy users without a store ID.
- `SiteConfigPresenter` builds the flat public API response, resolves localization and storefront defaults, and composes Showcase, Watch & Shop, and Best Sellers data.
- `SiteConfigUpdateRules` owns the complete direct-publish Settings validation registry. Its 229 rule paths match the previous controller rules exactly.
- `SiteConfigUpdater` owns persistence normalization: intentional blank contact email handling, partial theme merging, job-owned Showcase status preservation, and Watch & Shop reconciliation.
- `SiteConfigMediaService` owns SiteConfig media upload, deletion, job dispatch, poster cleanup, and Watch & Shop card assets.
- `SiteConfigModules` owns the supported module keys and their default state.
- `SiteConfigController` validates HTTP input, delegates to these services, and constructs HTTP responses.

## Preserved contracts

- The existing route URLs and methods remain unchanged.
- The public response remains flat and its exact top-level keys are still protected by the Phase 0 contract tests.
- Authenticated responses alone include `localized_content`.
- Existing draft/publish/version behavior remains separate from direct Settings updates.
- Media collections, limits, MIME rules, response bodies, and job dispatch behavior remain unchanged.
- Admins with a store ID resolve explicitly to their tenant. The current-store scope remains as a compatibility fallback for development fixtures and legacy users without a store ID.

Four private cross-field validation methods were removed from the old controller because they had no callers. Existing feature tests explicitly allow the partial draft states those dead methods would have rejected, so invoking them during this refactor would have changed the API contract.

## Next boundary

The code is now ready for an additive JSON document migration. That migration should introduce a versioned website-document owner and dual-read comparison before changing the flat API or removing existing columns. Operational settings, tenant identity, foreign keys, and job-owned state should remain typed.

# SiteConfig refactor: Phase 0 contract inventory

Status: completed inventory; no runtime behavior or schema changes.

This document is the boundary for the SiteConfig refactor. It records the current API, storage, write paths, tenant rules, and frontend consumers so later phases can change internals without accidentally changing storefront behavior.

## Current shape

- `site_config` is one row per store, enforced by a unique `store_id`.
- The model exposes 71 mass-assignable fields, 43 casts, tenant scoping, activity logging, realtime broadcasting, sanitizing mutators, defaults, and media relationships.
- `GET /api/v1/site-config` is a shared public/admin read endpoint. Public reads resolve the store from the storefront host; authenticated reads prefer the user's `store_id`; `?store=slug` is an unauthenticated development/login-theme compatibility path.
- `PATCH /api/v1/site-config` immediately updates published settings.
- `/api/v1/site-config/draft*` stores private JSON drafts and publishes selected fields transactionally into `site_config`.
- `site_config_versions.payload` stores the previous published Website Editor snapshot.
- Store Admin Settings remains a direct-publish client. Website Editor is the draft/publish client.

## Field ownership matrix

`Document` means versioned website content. `Operational` means live application behavior that should remain typed and immediately consistent. `Mixed` means the current field serves both presentation and backend behavior and must be split deliberately before schema migration.

| Area | Current fields | Current writes | Current consumers | Proposed owner |
|---|---|---|---|---|
| Tenant identity | `id`, `store_id`, `created_at`, `updated_at` | provisioning and database | tenant resolution, drafts, versions, readiness | Relational SiteConfig identity and publication metadata |
| Localization | `default_locale`, `supported_locales`, `localized_content` | Website Editor | public presenter, locale picker, SEO/page content | Document: `localization` |
| Brand and theme | `site_name`, `theme`, `logo_alt_text`, `logo_size`, `footer_logo_size`, `loader_animation` | Website Editor for brand fields; Settings for theme and loader animation | header, footer, loaders, SEO, storefront theme | Document for brand/theme; decide whether loader behavior stays operational |
| Hero | `hero_title`, `hero_subtitle`, `hero_overlay_color`, `hero_overlay_opacity`, `hero_full_bleed`, `hero_focal_x`, `hero_focal_y`, `hero_image_url`, `hero_media_mime` | Website Editor and legacy Settings validation | homepage hero and preview | Document: `home.hero`; media identity remains relational |
| About and story | `about_content`, `about_overlay_color`, `about_overlay_opacity`, `about_image_url`, `about_highlights`, `story_page` | Website Editor for content; legacy direct update supports overlays | About, Story, preview | Document: `pages.about` and `pages.story` |
| Contact presentation and routing | `contact_overlay_color`, `contact_overlay_opacity`, `contact_image_url`, `contact_page`, `contact_email`, `contact_phone`, `contact_entries`, `social_links` | Website Editor and legacy direct update | Contact/Footer/JSON-LD plus Contact and Commission email recipients | Mixed: document display fields; typed notification-recipient settings |
| Storefront appearance | `badge_in_stock_color`, `header_cta`, `footer_banner`, `footer` | Settings for badge; Website Editor for header/footer | stock badge, header, footer, banner | Document: `theme.stock_badge`, `header`, `footer` |
| Homepage composition | `homepage_sections`, `homepage_steps`, `homepage_features`, `homepage_stats`, `homepage_statement`, `homepage_newsletter` | Website Editor and legacy direct update | homepage and preview | Document: `home.sections` and `home.blocks` |
| Homepage merchandising | `homepage_showcase`, `homepage_watch_shop`, `homepage_best_sellers` | Settings direct publish; media/job callbacks also update state | homepage, Settings, product/order/media resolvers | Document configuration plus separate derived runtime projection/status |
| Page content | `shop_header`, `shop_promo`, `blog_page`, `blog_overlay_color`, `blog_overlay_opacity`, `blog_image_url`, `services_page`, `services_overlay_color`, `services_overlay_opacity`, `services_image_url` | Website Editor for page content/media; legacy direct update supports all | Shop, Blog, Services and preview | Document: `pages.shop`, `pages.blog`, `pages.services` |
| Popup | `welcome_popup_enabled`, `welcome_popup_heading`, `welcome_popup_body`, `welcome_popup_discount_id` | Website Editor handles enabled/copy; Settings handles discount selection | popup rendering and subscription discount issuance | Mixed: document presentation; typed discount foreign key and activation policy |
| Module switches | `modules_enabled` | Settings direct publish | frontend route middleware, commissions, gift cards, readiness | Operational typed settings or a validated feature-flags value object |
| Commerce | `favorites_enabled`, `show_stock_quantity`, `backorder_enabled`, `backorder_payment_link_expiry_hours`, `currency_code` | Settings or dedicated Backorder endpoint | products, checkout, orders, backorders, readiness, frontend controls | Operational typed settings |
| SEO | `default_seo_title`, `default_seo_description`, `default_og_image_url`, `pages_seo`, `canonical_base_url` | Website Editor covers title/description/page subset; Settings covers full set | SEO composable and JSON-LD | Document: `seo`; consolidate the two editors before migration |

## Media contract

Media remains relational and tenant-owned. The public response exposes derived URLs for `logo`, `favicon`, `cart_icon`, `footer_logo`, `loader_logo`, `hero`, `homepage_statement`, `story_image_a`, `story_image_b`, `about`, `contact`, `blog`, `services`, `showcase_video`, and `showcase_video_poster`.

The columns `hero_image_url`, `about_image_url`, `contact_image_url`, `blog_image_url`, and `services_image_url` currently coexist with Media rows. The presenter prefers the stored URL and falls back to the Media URL. A later phase must choose one source of truth and migrate it explicitly.

Website Editor drafts additionally expose media IDs and URLs. These are draft references, not candidates for arbitrary JSON URLs without ownership validation.

## Write-path differences

Website Editor publishes these areas: localization; basic SEO title/description/page data; store display details; brand media and sizing; hero; homepage steps/features/stats/statement/newsletter/order; header/footer; About/Story/Contact/Shop/Blog/Services content; and popup enabled/copy.

Store Admin Settings directly publishes: theme; stock badge colour; favourites; stock quantity visibility; popup discount; showcase; Watch and Shop; best sellers; modules; full SEO defaults; canonical URL; loader animation; and currency.

The following published fields are outside `HomepageSnapshot::FIELDS` and therefore do not participate in Website Editor versioning: `theme`, `about_overlay_color`, `about_overlay_opacity`, `contact_overlay_color`, `contact_overlay_opacity`, `badge_in_stock_color`, `favorites_enabled`, `show_stock_quantity`, `backorder_enabled`, `backorder_payment_link_expiry_hours`, `welcome_popup_discount_id`, `homepage_showcase`, `homepage_watch_shop`, `homepage_best_sellers`, `blog_overlay_color`, `blog_overlay_opacity`, `services_overlay_color`, `services_overlay_opacity`, `modules_enabled`, `default_og_image_url`, `canonical_base_url`, `loader_animation`, and `currency_code`.

This separation is intentional until Phase 1 assigns a single schema definition to each area. Publishing a Website Editor draft must continue to preserve operational settings.

## Public API contract

`GET /api/v1/site-config` currently returns a flat object. Storage may become nested later, but this response must remain compatible until a separately versioned frontend migration is approved.

The response contains:

- tenant/routing metadata: `id`, `is_storefront_host`, `canonical_host`, `updated_at`;
- locale data: `default_locale`, `supported_locales`, `locale`, and authenticated-only `localized_content`;
- every presentation and operational field shown in the ownership matrix except `store_id`, timestamps other than `updated_at`, and `backorder_payment_link_expiry_hours`;
- derived media URLs: `logo_url`, `favicon_url`, `cart_icon_url`, `footer_logo_url`, `loader_logo_url`, and page media URLs;
- resolved structures: `theme`, `story_page`, `homepage_statement`, `homepage_showcase`, `homepage_watch_shop`, `homepage_best_sellers`, `modules_enabled`, `header_cta`, `footer_banner`, and `footer`.

`homepage_showcase`, `homepage_watch_shop`, and `homepage_best_sellers` contain server-derived data and cannot be treated as raw persisted JSON at the API boundary.

## Frontend consumers

- `useSiteConfig.ts` owns the flat public `SiteConfig` TypeScript contract and routing-safe fallbacks.
- `useWebsiteEditorDraft.ts` owns the draft payload contract and normalization from the published response.
- `pages/admin/settings.vue` is the remaining direct-publish client.
- `pages/admin/website-editor.vue` is the draft/publish client and composes the draft with server-resolved merchandising blocks for preview.
- Storefront consumers include the global app/layout, Header, Footer, popup, homepage sections, About, Story, Contact, Shop, Blog, Services, SEO, JSON-LD, module middleware, currency, favourites, and stock UI.

The backend may be refactored without changing these consumers as long as the flat read contract and both existing write contracts remain stable during the transition.

## Invariants for every later phase

1. Authenticated SiteConfig reads resolve by the user's `store_id`; public reads resolve by storefront host. No cross-tenant fallback may expose another tenant's configuration.
2. Explicit blank editable content remains blank. Defaults may fill absent structure but must not repopulate intentionally cleared copy.
3. Draft save, optimistic locking, review, scheduling, version history, media ownership, and atomic publishing remain intact.
4. Operational settings cannot be rolled back accidentally by publishing an older website document.
5. Public consumers never receive `localized_content`; authenticated editors may receive it.
6. Media URLs exposed in documents must still resolve to tenant-owned Media records or a deliberately retained legacy URL field.
7. The flat public response remains stable until a versioned frontend migration is completed.
8. MySQL production behavior and SQLite test behavior must remain compatible.

## Confirmed drift and follow-up decisions

- `BackorderController` reads `SiteConfig::admin_email`, but no such SiteConfig column or model field exists. Phase 1 should route notifications through one typed recipient service after deciding whether the fallback is `contact_email`, contact entries, store-admin users, or a new operational address.
- Story defaults differ between the public resolver and the Website Editor snapshot. Phase 1 must establish one defaults provider while preserving intentional blanks.
- SiteConfig field definitions are duplicated across the migration, model fillable/casts, controller validation, draft validation, snapshot fields/defaults, publish changed-area labels, API serialization, and frontend TypeScript types.
- Background media jobs call `SiteConfig::first()` and rely on ambient tenant scope. Their tenant identity should become explicit before queue behavior is changed.
- Migration squashing is allowed only after confirming every retained environment can be rebuilt. Otherwise Phase 4 must use additive migration and backfill steps.

## Phase 1 entry criteria

Phase 1 may centralize schema definitions and defaults without changing database storage. It should begin with one bounded section, prove identical public/draft output, and then repeat section by section. Traits are reserved for cross-cutting model behavior; schema, validation, normalization, and presentation belong in explicit classes.

# SiteConfig refactor: Phase 1 website document contract

Status: completed internal refactor; no database, route, or response-shape changes.

Phase 1 gives the versioned Website Editor data one explicit internal contract. The published `site_config` columns and the flat API remain in place so Store Admin and storefront clients do not need a coordinated frontend release.

## New ownership

- `WebsiteDocumentSchema` owns draft fields, compatibility field groups, null normalization, and the labels used to summarize publish changes.
- `WebsiteDocumentDefaults` owns structural defaults. It provides separate draft and public profiles for the few places where existing behavior intentionally differs.
- `WebsiteDocumentRules` owns validation for a complete Website Editor payload.
- `HomepageSnapshot` remains the mapper between the relational `SiteConfig` model and the website document. It now consumes the schema and defaults instead of redefining them.
- `SaveSiteConfigDraftRequest` owns only the request envelope and messages. Both draft saves and publish-time revalidation consume `WebsiteDocumentRules`.
- `WebsiteDraftPublisher` owns publishing behavior and uses the schema's area map when reporting changes.
- `SiteConfigController` remains the public presenter for this phase and now consumes the shared public defaults.

## Preserved behavior

The refactor keeps the two existing default profiles visible rather than accidentally merging them:

- a new private draft begins with blank editable copy;
- an absent published footer, statement, or story structure may still receive the existing storefront fallback copy;
- a persisted empty string remains empty because public resolvers fall back only when a value is absent or null;
- operational settings outside the website document are not included in draft persistence or version restoration;
- tenant resolution, media ownership, optimistic locking, scheduling, and the flat public response are unchanged.

## Drift protection

`WebsiteDocumentContractTest` enforces that every draft field has exactly one top-level validation rule and one publish-area owner. It also checks that every Website Editor media ID and URL participates in the document contract, and that request-time and publish-time validation use the same rule registry.

When adding a Website Editor field, update its schema ownership, validation, snapshot mapping/default behavior, public presentation if applicable, and API contract test in the same change. Operational fields should remain outside this document until a later phase assigns them a typed settings owner.

## JSON storage recommendation

Do not convert the entire `site_config` row into an unstructured JSON column. The safer target is a versioned `website_document` JSON payload for presentation content, while tenant identity, commerce controls, feature switches, foreign keys, notification routing, and publication metadata stay typed.

Phase 1 prepares that migration without performing it. A later additive migration can backfill the document, compare old and new reads, and retain a rollback path before any legacy columns are removed or migrations are squashed.

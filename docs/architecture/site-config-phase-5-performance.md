# SiteConfig Phase 5: measured query reduction

## Scope

Phase 5 optimizes the established SiteConfig document architecture without changing routes, response keys, tenant resolution, localization behavior, or the frontend contract. The work targets repeated database queries in the public SiteConfig read and website-editor publish paths.

## Public read path

`SiteConfigResolver` previously eager-loaded fifteen separate `morphOne` media relationships. `WebsiteDocumentStore` also built the legacy snapshot, including another media query, before checking whether a supported document already existed.

The read path now:

- loads one constrained `storefrontMedia` relationship containing only live website assets, Showcase media, Watch & Shop media, and generated posters;
- indexes that collection once by collection name and media ID inside `SiteConfigPresenter`;
- builds the legacy snapshot only when the document is missing or its schema version is unsupported;
- derives relational media identifiers for draft/version snapshots from the already-loaded collection;
- skips the Best Sellers sales aggregation while that section is disabled.

The public endpoint has a test-enforced budget of at most five database queries for the default configuration. The test also requires exactly one media query, exactly one document query, and no Best Sellers aggregation when the feature is disabled.

## Draft and publish path

Website asset operations previously queried each of twelve editor slots independently during validation, publication, unused-draft cleanup, and full draft cleanup. The cost increased with every new slot.

`WebsiteEditorAssets` now batches:

- selected-asset validation into one tenant-bound media query;
- current and target publication assets into one tenant-bound media query;
- unused draft assets into one query across all draft collections;
- discard cleanup into one query across all draft collections.

The publish feature test enforces a budget of at most four media-select queries for a normal document-only publish, including the snapshots used before and after publication. Asset ownership and allowed collection checks still run for each slot after the batch is loaded.

## Direct media consistency

Direct published-media uploads now update the matching document URL and media metadata explicitly, while deletion clears them. This prevents an older URL stored in the document from hiding a newly uploaded relational media row. These changes also touch `site_config` so draft conflict detection and realtime refresh signals observe the published change.

## Index and cache decision

No new index is required for these queries. The media table already has a composite index on `imageable_type`, `imageable_id`, and `collection`; document and SiteConfig tenant lookups are protected by unique keys.

No response cache was added. SiteConfig output varies by tenant, authenticated state, locale cookie, browser language, product state, sales data, and media-processing state. Query consolidation removes the repeated work without introducing a broad invalidation surface or risking cross-tenant cache reuse.

## Measurement boundary

The query budgets run through Laravel feature requests on the isolated SQLite testing database. They protect query shape and count, not production network latency or database execution time. Production timing should be measured separately with representative tenant data after deployment.

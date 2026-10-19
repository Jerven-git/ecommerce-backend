# SiteConfig Phase 6: release readiness

## Final architecture

SiteConfig now has a clear split between operational settings and website content:

- `site_config` keeps tenant ownership and settings that the application reads directly, including contact routing, overlays, Showcase, Watch & Shop, and Best Sellers configuration;
- `site_config_documents` stores the versioned published website document as JSON;
- `site_config_drafts` and `site_config_versions` hold private editor state and publish history;
- `WebsiteDocumentSchema`, `WebsiteDocumentDefaults`, `WebsiteDocumentRules`, and `WebsiteDocumentSanitizer` own the content contract;
- `WebsiteDocumentStore` owns document reads and writes, including the temporary legacy fallback for a missing or unsupported document;
- the SiteConfig resolver, updater, presenter, media service, draft publisher, and asset service each own one part of the request flow.

The existing API routes and response keys remain unchanged. The frontend does not need a coordinated refactor for this release.

## Development database reset

This branch consolidates the historical SiteConfig migrations because the application is still in development. Existing local databases retain their old migration history and must be recreated before exercising the new schema.

If local data matters, export it before resetting. Then run the reset in the intended development environment:

```bash
php artisan migrate:fresh --seed
```

This command destroys every table in the selected database. Confirm the active environment and database connection before running it. It must not be used against a shared or production database.

If this schema is ever released without resetting the database, restore the removed additive migrations and introduce a forward-only data migration that builds `site_config_documents` from the existing rows. Do not deploy the consolidated development migration history to a database that has already run the removed migrations.

## Release checks

Run these checks after recreating the development database:

1. Read public SiteConfig as a guest on two different storefront hosts and confirm tenant isolation.
2. Read and update SiteConfig as each store administrator and confirm the authenticated store wins over the request host.
3. Save a private draft, reload it, publish it, restore a prior version, and discard a draft.
4. Upload and delete each SiteConfig image slot, plus Showcase and Watch & Shop video media; run a queue worker and confirm processing reaches `ready`.
5. Confirm direct SiteConfig media changes update the published document and invalidate draft conflict timestamps.
6. Confirm intentionally blank editable fields stay blank on the public response.
7. Run the full automated test suite and the isolated testing migration/seed command.

Restart queue workers after deploying application code so long-running workers load the current jobs and models:

```bash
php artisan queue:restart
```

## Performance boundary

The public SiteConfig feature test enforces at most five queries for a default read, including one media query and one document query. The publish test enforces at most four media-select queries for a normal document-only publish. These budgets guard query shape on SQLite; collect timing with representative tenant data on the deployment database before setting latency targets.

## Rollback

The safe rollback unit is application code and its matching database backup. Removing only `site_config_documents` would make the compatibility reader fall back to legacy columns, but those presentation columns no longer exist in a fresh consolidated schema. Preserve a database backup until the reset, seed, editor workflow, media processing, and tenant smoke checks have passed.

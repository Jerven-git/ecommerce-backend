# SiteConfig Phase 4: website document cutover

## Decision

`site_config_documents.payload` is the authoritative store for tenant-managed website presentation. `site_config` now contains only settings used by application behavior, queries, relationships, or background jobs.

The public and authenticated API responses remain flat. Controllers and presenters compose the JSON document with operational settings, so the frontend keeps the same routes and response keys.

## Ownership

The versioned document owns brand copy, page content, localization, page SEO, presentation metadata, editor asset references, homepage composition, header and footer content, and welcome-popup copy.

Typed `site_config` columns retain:

- theme and overlay behavior;
- favorites, inventory visibility, backorder behavior, and payment-link expiry;
- the welcome-popup discount relationship;
- Showcase, Watch & Shop, and Best Sellers operational or job-owned state;
- module switches;
- default Open Graph and canonical URL settings;
- loader animation and currency.

`contact_email` and `contact_entries` are also retained as typed operational projections because contact-message routing reads them outside the website presentation layer. Direct settings updates and draft publication update the document and these routing fields in one database transaction.

## Write paths

There is no model observer or general dual write.

- `SiteConfigUpdater` separates validated presentation and operational fields. It merges presentation changes into the current document and updates typed fields explicitly.
- `WebsiteDraftPublisher` archives the current document, validates the draft, writes the new document, updates the contact-routing projection, publishes related media, and removes the draft in one transaction.
- `StoreProvisioner` creates a published default document with every new tenant.
- `SiteConfigResolver` repairs a missing companion document from the normalized compatibility snapshot. This covers development fixtures and recovery-created configuration rows.
- `WebsiteDocumentStore` filters all writes through `WebsiteDocumentSchema`, applies the former model sanitization rules, and requires the document tenant to match the configuration tenant.

Reads accept only the current document schema version. A missing or unsupported document falls back to `HomepageSnapshot::fromLegacyConfig()`. The fallback exists for migration and recovery; the fresh Phase 4 schema has no presentation columns, so its result is the published default profile plus relational media.

## Development migration consolidation

The project is still in development, so migration history now describes the target schema directly:

- the original `site_config` migration creates only operational columns;
- draft review and scheduling fields are part of the draft-table creation migration;
- the version table is created with release metadata from the start;
- eight obsolete incremental migrations were removed;
- the document-table migration still backfills an older development database when it runs against legacy presentation columns.

Because applied migration files were consolidated, existing development databases must be rebuilt with `php artisan migrate:fresh` before using this branch. This is a development reset and destroys data in the selected database. A database that must retain data should instead be exported or migrated with a purpose-built transition before the old history is discarded.

## Boundaries and rollback

Tenant identity, media rows, discount relationships, and operational settings remain relational. Media files and their lifecycle are not embedded in the JSON payload.

The Phase 3 rollback path depended on presentation columns that Phase 4 removes from a fresh schema. Rollback now means restoring the pre-cutover code and database together from a backup or rebuilding a development database from the earlier migration history. Dropping only `site_config_documents` would discard presentation content.

## Verification

Contract tests assert that every document field has validation and a publish area, every default maps to the centralized schema, a fresh `site_config` table excludes presentation columns, resolver recovery creates a missing document, and document rows remain tenant-bound. Feature tests continue to protect the unchanged flat API, draft conflict behavior, localization, editorial workflow, media ownership, sanitization, and direct Settings updates.

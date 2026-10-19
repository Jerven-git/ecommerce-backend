# SiteConfig Phase 3: versioned website document

## Decision

Store tenant-managed website presentation content in a separate, one-row-per-tenant `site_config_documents` table. The row contains a versioned JSON payload whose keys are limited to `WebsiteDocumentSchema::FIELDS`.

Keep operational settings as typed columns on `site_config`. Examples include currency, favorites, stock and backorder behavior, module switches, discount relationships, canonical URLs, media processing state, and product-derived homepage sections. These values participate in application behavior and queries, so moving them into an unrestricted JSON object would weaken validation and make the data harder to query safely.

This structure avoids turning the existing table into a mixture of many presentation columns and operational configuration while retaining relational constraints:

- `site_config_id` is unique and cascades on deletion.
- `store_id` is unique, tenant-bound, and cascades on deletion.
- `schema_version` identifies the document format.
- `payload` contains only the shared presentation schema.

## Migration and backfill

`2026_10_19_000001_create_site_config_documents_table.php` creates the table and backfills every existing `site_config` row in chunks. Backfill uses the same snapshot/default normalization as runtime writes, avoiding a second field list inside the migration.

The observer checks whether the document table exists. This allows earlier migrations, rollback, and a backfill test to save legacy rows before the new table is available.

## Compatibility period

The existing API shape stays flat. No route or response key changes are introduced, so the frontend does not need a matching refactor in this phase.

During the compatibility period:

1. `SiteConfigObserver` writes presentation fields to the document whenever a legacy `SiteConfig` model is saved.
2. Direct settings updates and website-editor publishing perform the legacy update and document write inside the same database transaction.
3. `HomepageSnapshot::fromConfig()` reads the supported document version first.
4. Missing documents and unsupported versions fall back to normalized legacy columns.
5. Stored null values do not erase structural legacy defaults unless null is also the valid legacy value.

Legacy columns remain populated, making rollback safe: the migration `down()` removes only the derived document table. Existing application data remains available from `site_config`.

## Tenant and schema boundaries

Document lookup requires both `site_config_id` and `store_id`, even though the database also enforces one row for each. Reads bypass the global scope only inside the storage service, where the two tenant identifiers are checked together. Payload writes discard keys outside the centralized presentation schema.

The document does not accept an unknown schema version. A newer payload falls back to legacy columns until code that understands that version is deployed.

## Performance

Building the compatibility snapshot previously queried every media slot separately. Phase 3 loads all website-editor media slots in one query and indexes them by collection before constructing the document.

## Phase 4 boundary

Phase 4 should verify payload parity, consolidate the development migration history, and decide when to remove duplicated presentation columns and the compatibility dual write. Typed operational columns should remain relational. Removal should happen only after the document path has passed the complete regression suite and a rollback plan has been retained.

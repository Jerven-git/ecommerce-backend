# Backend modularization: Phase 13 release readiness

## Outcome

The modularization track now has a repeatable backend release gate and a forward-safe deployment procedure. This phase does not deploy code or apply migrations. It separates locally verified code readiness from environment, database, migration, worker, scheduler, and production smoke-test readiness.

## Automated release gate

Run the read-only command inside the intended backend runtime:

```bash
php artisan backend:release-check
```

It verifies:

- `APP_KEY` is configured;
- the configured database is reachable;
- the migration repository exists and no migrations are pending;
- both webhook ledger tables contain the Phase 12 processing-state columns;
- the application environment and debug setting are production-safe; and
- persistent cache and asynchronous queue drivers are configured.

For a pre-deploy check where the release migration is expected to be pending:

```bash
php artisan backend:release-check --allow-pending
```

For the post-deploy gate, warnings must also fail the command:

```bash
php artisan backend:release-check --strict
```

The command performs reads only. It does not migrate, clear caches, restart workers, write probe records, or contact payment providers.

## Release sequence

1. Confirm the intended branch, commit, image, environment, and database target.
2. Take and verify a restorable database backup.
3. Run `composer validate --strict` and `composer audit --locked`, then run the full backend test suite against the release artifact.
4. Run `php artisan backend:release-check --allow-pending` inside the intended backend runtime.
5. Drain traffic or enable the deployment platform's maintenance mechanism if the platform cannot apply the migration before new application instances receive webhook traffic.
6. Deploy the backend artifact.
7. Apply forward migrations with `php artisan migrate --force`. Never use `migrate:fresh` on an existing or shared database.
8. Rebuild Laravel caches with `php artisan optimize:clear` followed by `php artisan optimize`.
9. Restart long-running workers with `php artisan queue:restart`, then confirm workers and the scheduler are running.
10. Run `php artisan backend:release-check --strict` and `php artisan migrate:status`.
11. Restore traffic only after the strict gate and health checks pass.

The Phase 12 migration is additive. It adds webhook processing-state columns and marks existing ledger rows as processed, preserving historical deduplication. The previous application code tolerates the additional columns, so the safe rollback is normally a code rollback while leaving the additive migration in place.

## Production verification

Verify these states separately:

- `/up` returns success through the production ingress.
- `php artisan schedule:list` includes payment expiry every 15 minutes, backorder-token expiry every 15 minutes, and subscription expiry daily at 03:00.
- Queue workers are consuming the configured asynchronous queue after restart.
- `php artisan migrate:status` reports the webhook processing-state migration as run.
- A sandbox or controlled provider event records `attempts = 1` and a non-null `processed_at`.
- A duplicate of that controlled event does not repeat the domain transition.
- Logs contain structured webhook context without payloads, credentials, or raw exception messages.
- Order status counts remain isolated when checked with administrators from two different stores.
- Creating or updating an order, payment, or product refreshes the affected store's order/dashboard aggregates.

Provider smoke tests must use an approved sandbox or a controlled real transaction. Do not fabricate provider signatures against production secrets or weaken webhook verification and rate limits.

## Rollback and recovery

- Roll back application code and restart workers if the application release fails after migration.
- Leave the additive webhook columns and their data in place; dropping them is not required for an application rollback.
- Do not run the migration `down()` method while Phase 12 code is active.
- If migration application fails, keep traffic drained, restore the verified database backup when necessary, and investigate the exact database error before retrying.
- If webhook processing fails after release, retain the ledger row. Its null `processed_at`, failure timestamp, and attempt count make the event eligible for a provider retry.
- Do not manually change `processed_at` or replay production webhooks without first confirming the corresponding payment or subscription domain state.

## Validation status for this phase

Local automated validation covers the release command, current schema, operational schedule registration, health endpoint, optimized bootstrap generation, isolated migration/seed execution, dependency manifest and advisory checks, the complete test suite, formatting, and diff integrity.

Production database connectivity, migration execution, queue consumption, scheduler execution, ingress health, provider callbacks, and deployment remain unverified until the release procedure is run in the target environment.

## Deliberate exclusions

No SiteConfig or Website Editor implementation was changed. No frontend or infrastructure file was modified. This phase does not deploy, migrate a live database, restart production services, or send provider traffic.

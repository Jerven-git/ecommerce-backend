<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class CheckBackendReleaseReadiness extends Command
{
    protected $signature = 'backend:release-check
        {--allow-pending : Report pending migrations as a warning for a pre-deploy check}
        {--strict : Treat operational configuration warnings as failures}';

    protected $description = 'Run read-only backend database, migration, schema, and runtime configuration checks';

    /** @var list<array{string, string, string}> */
    private array $results = [];

    public function handle(Migrator $migrator): int
    {
        $this->results = [];

        $this->checkApplicationKey();
        $databaseReady = $this->checkDatabaseConnection();

        if ($databaseReady) {
            $this->checkMigrations($migrator);
            $this->checkWebhookLedgerSchema();
        }

        $this->checkOperationalConfiguration();

        $this->table(['check', 'status', 'detail'], $this->results);

        $failures = count(array_filter($this->results, fn (array $result): bool => $result[1] === 'FAIL'));
        $warnings = count(array_filter($this->results, fn (array $result): bool => $result[1] === 'WARN'));

        if ($failures > 0 || ($this->option('strict') && $warnings > 0)) {
            $this->error("Release check failed with {$failures} failure(s) and {$warnings} warning(s).");

            return self::FAILURE;
        }

        $this->info("Release check passed with {$warnings} warning(s).");

        return self::SUCCESS;
    }

    private function checkApplicationKey(): void
    {
        $configured = filled(config('app.key'));

        $this->record(
            'application key',
            $configured ? 'PASS' : 'FAIL',
            $configured ? 'configured' : 'APP_KEY is missing',
        );
    }

    private function checkDatabaseConnection(): bool
    {
        try {
            DB::connection()->getPdo();
            $this->record('database connection', 'PASS', (string) DB::connection()->getDriverName());

            return true;
        } catch (Throwable $exception) {
            $this->record('database connection', 'FAIL', $exception::class);

            return false;
        }
    }

    private function checkMigrations(Migrator $migrator): void
    {
        if (! $migrator->repositoryExists()) {
            $this->record('migration repository', 'FAIL', 'migrations table is missing');

            return;
        }

        $migrationFiles = array_keys($migrator->getMigrationFiles(database_path('migrations')));
        $pending = array_values(array_diff($migrationFiles, $migrator->getRepository()->getRan()));

        if ($pending === []) {
            $this->record('pending migrations', 'PASS', 'none');

            return;
        }

        $status = $this->option('allow-pending') ? 'WARN' : 'FAIL';
        $this->record('pending migrations', $status, implode(', ', $pending));
    }

    private function checkWebhookLedgerSchema(): void
    {
        $columns = ['attempts', 'processed_at', 'failed_at', 'last_error'];

        foreach (['payment_webhook_events', 'subscription_webhook_events'] as $table) {
            $ready = Schema::hasTable($table) && Schema::hasColumns($table, $columns);
            $this->record(
                "{$table} schema",
                $ready ? 'PASS' : 'FAIL',
                $ready ? 'processing state available' : 'required processing columns are missing',
            );
        }
    }

    private function checkOperationalConfiguration(): void
    {
        $this->record(
            'application environment',
            app()->environment('production') ? 'PASS' : 'WARN',
            (string) app()->environment(),
        );
        $this->record(
            'debug mode',
            config('app.debug') ? 'WARN' : 'PASS',
            config('app.debug') ? 'enabled' : 'disabled',
        );
        $this->record(
            'cache store',
            config('cache.default') === 'array' ? 'WARN' : 'PASS',
            (string) config('cache.default'),
        );
        $this->record(
            'queue connection',
            config('queue.default') === 'sync' ? 'WARN' : 'PASS',
            (string) config('queue.default'),
        );
    }

    private function record(string $check, string $status, string $detail): void
    {
        $this->results[] = [$check, $status, $detail];
    }
}

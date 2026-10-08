<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class TransferSqliteToSupabase extends Command
{
    protected $signature = 'db:transfer-sqlite-to-supabase
        {--source= : SQLite file (defaults to database/database.sqlite)}
        {--dry-run : Show source/target row counts without copying data}';

    protected $description = 'Copy application data from the local SQLite database into an empty Supabase PostgreSQL database';

    private array $excludedTables = [
        'migrations', 'cache', 'cache_locks', 'failed_jobs', 'job_batches', 'jobs',
        'password_reset_tokens', 'personal_access_tokens', 'sessions',
    ];

    public function handle(): int
    {
        if (config('database.default') !== 'pgsql') {
            $this->error('Set DB_CONNECTION=pgsql in .env and configure your Supabase connection first.');
            return self::FAILURE;
        }
        if (! in_array('pgsql', \PDO::getAvailableDrivers(), true)) {
            $this->error('The PHP pdo_pgsql extension is missing. Install/enable it, then restart PHP before transferring.');
            return self::FAILURE;
        }

        $sourcePath = $this->option('source') ?: database_path('database.sqlite');
        if (! is_file($sourcePath)) {
            $this->error("SQLite source not found: {$sourcePath}");
            return self::FAILURE;
        }

        try {
            config(['database.connections.sqlite_transfer' => array_merge(
                config('database.connections.sqlite'),
                ['database' => $sourcePath, 'url' => null]
            )]);
            DB::purge('sqlite_transfer');
            $source = DB::connection('sqlite_transfer');
            $target = DB::connection('pgsql');
            $target->select('SELECT 1');

            $tables = collect($source->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"))
                ->pluck('name')
                ->reject(fn (string $table) => in_array($table, $this->excludedTables, true))
                ->values()
                ->all();
            foreach ($tables as $table) {
                if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) {
                    throw new RuntimeException('Unexpected SQLite table name; transfer stopped for safety.');
                }
            }

            $missing = array_values(array_filter($tables, fn (string $table) => ! Schema::connection('pgsql')->hasTable($table)));
            if ($missing) {
                throw new RuntimeException('Run `php artisan migrate --force` against Supabase first. Missing tables: '.implode(', ', $missing));
            }

            $orderedTables = $this->dependencyOrder($source, $tables);
            $counts = [];
            foreach ($orderedTables as $table) {
                $counts[$table] = (int) $source->table($table)->count();
            }

            $occupied = [];
            foreach ($tables as $table) {
                $count = (int) $target->table($table)->count();
                if ($count > 0) $occupied[$table] = $count;
            }
            if ($occupied) {
                throw new RuntimeException('Supabase already contains application rows; transfer stopped to prevent duplicates or overwrites. Nonempty tables: '.implode(', ', array_keys($occupied)).'. Use a fresh Supabase database or arrange a separate merge.');
            }

            $this->table(['Table', 'SQLite rows', 'Supabase rows'], collect($counts)->map(fn ($count, $table) => [$table, $count, 0])->values()->all());
            if ($this->option('dry-run')) {
                $this->info('Dry run complete. No rows were changed.');
                return self::SUCCESS;
            }
            if (! $this->confirm('Copy these rows to the empty Supabase database? The local SQLite database will be left unchanged.', false)) {
                $this->warn('Transfer cancelled.');
                return self::SUCCESS;
            }

            $target->transaction(function () use ($source, $target, $orderedTables, &$counts): void {
                foreach ($orderedTables as $table) {
                    $source->table($table)->orderByRaw('rowid')->chunk(250, function ($rows) use ($target, $table): void {
                        $target->table($table)->insert($rows->map(fn ($row) => (array) $row)->all());
                    });
                }

                foreach ($orderedTables as $table) {
                    if (! Schema::connection('pgsql')->hasColumn($table, 'id')) continue;
                    $quotedTable = $target->getQueryGrammar()->wrapTable($table);
                    $sequence = $target->selectOne("SELECT pg_get_serial_sequence('public.{$table}', 'id') AS sequence")->sequence ?? null;
                    if ($sequence) {
                        $target->selectOne(
                            'SELECT setval(?::regclass, COALESCE((SELECT MAX(id) FROM '.$quotedTable.'), 1), (SELECT MAX(id) FROM '.$quotedTable.') IS NOT NULL)',
                            [$sequence]
                        );
                    }
                }
            });

            $this->info('SQLite data copied successfully. The source database remains unchanged.');
            $this->line('Create an admin account after transfer with: php artisan admin:create admin@example.com "Admin Name"');
            return self::SUCCESS;
        } catch (Throwable $e) {
            report($e);
            $this->error('Transfer stopped: '.$e->getMessage());
            return self::FAILURE;
        }
    }

    /** Keep parent rows ahead of rows that reference them. */
    private function dependencyOrder(Connection $source, array $tables): array
    {
        $tableSet = array_fill_keys($tables, true);
        $dependencies = array_fill_keys($tables, []);
        foreach ($tables as $table) {
            foreach ($source->select('PRAGMA foreign_key_list("'.str_replace('"', '""', $table).'")') as $foreignKey) {
                $parent = (string) $foreignKey->table;
                if (isset($tableSet[$parent]) && $parent !== $table) $dependencies[$table][] = $parent;
            }
            $dependencies[$table] = array_values(array_unique($dependencies[$table]));
        }

        $ordered = [];
        while (count($ordered) < count($tables)) {
            $progress = false;
            foreach ($tables as $table) {
                if (in_array($table, $ordered, true)) continue;
                if (array_diff($dependencies[$table], $ordered) === []) {
                    $ordered[] = $table;
                    $progress = true;
                }
            }
            if (! $progress) throw new RuntimeException('Foreign-key cycle detected in SQLite schema; transfer order cannot be determined safely.');
        }
        return $ordered;
    }
}

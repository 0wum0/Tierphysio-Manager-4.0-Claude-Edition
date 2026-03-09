<?php

declare(strict_types=1);

namespace Saas\Services;

use Saas\Core\Database;
use Saas\Core\Config;

class MigrationRunner
{
    private string $migrationsPath;

    public function __construct(
        private Database $db,
        private Config   $config
    ) {
        $this->migrationsPath = $this->config->getRootPath() . '/migrations';
    }

    /**
     * Returns all migration files sorted by name.
     */
    public function getAll(): array
    {
        $sql = glob($this->migrationsPath . '/*.sql') ?: [];
        $php = glob($this->migrationsPath . '/*.php') ?: [];

        // Merge and sort by filename (basename without extension)
        $all = [];
        foreach (array_merge($sql, $php) as $path) {
            $name = pathinfo($path, PATHINFO_FILENAME);
            // If both .sql and .php exist for same name, prefer .php
            if (!isset($all[$name]) || pathinfo($path, PATHINFO_EXTENSION) === 'php') {
                $all[$name] = [
                    'name'    => $name,
                    'file'    => $path,
                    'type'    => pathinfo($path, PATHINFO_EXTENSION),
                    'ran'     => false,
                    'ran_at'  => null,
                    'batch'   => null,
                ];
            }
        }

        ksort($all);
        return array_values($all);
    }

    /**
     * Returns list of already-run migrations from the DB.
     */
    public function getRan(): array
    {
        try {
            $rows = $this->db->fetchAll("SELECT * FROM saas_migrations ORDER BY id ASC");
            $ran  = [];
            foreach ($rows as $row) {
                $ran[$row['migration']] = $row;
            }
            return $ran;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Returns pending (not yet run) migrations.
     */
    public function getPending(): array
    {
        $ran     = $this->getRan();
        $all     = $this->getAll();
        $pending = [];

        foreach ($all as $migration) {
            if (!isset($ran[$migration['name']])) {
                $pending[] = $migration;
            }
        }

        return $pending;
    }

    /**
     * Returns status of all migrations (ran or pending).
     */
    public function getStatus(): array
    {
        $ran    = $this->getRan();
        $all    = $this->getAll();
        $status = [];

        foreach ($all as $migration) {
            $name = $migration['name'];
            if (isset($ran[$name])) {
                $migration['ran']    = true;
                $migration['ran_at'] = $ran[$name]['ran_at'];
                $migration['batch']  = $ran[$name]['batch'];
            }
            $status[] = $migration;
        }

        return $status;
    }

    /**
     * Runs all pending migrations. Returns results array.
     */
    public function runPending(): array
    {
        $pending = $this->getPending();
        $results = [];

        if (empty($pending)) {
            return [['name' => null, 'status' => 'nothing', 'message' => 'Keine ausstehenden Migrationen.']];
        }

        $batch = $this->getNextBatch();

        foreach ($pending as $migration) {
            $result = $this->runOne($migration, $batch);
            $results[] = $result;
            if ($result['status'] === 'error') {
                break; // Stop on first error
            }
        }

        return $results;
    }

    /**
     * Runs a single migration file.
     */
    public function runOne(array $migration, int $batch): array
    {
        // PHP migrations
        if (($migration['type'] ?? 'sql') === 'php') {
            return $this->runPhpMigration($migration, $batch);
        }

        $sql = file_get_contents($migration['file']);
        if (!$sql) {
            return [
                'name'    => $migration['name'],
                'status'  => 'error',
                'message' => 'SQL-Datei konnte nicht gelesen werden.',
            ];
        }

        // Strip -- line comments, then split on ; and execute non-empty statements
        $lines = explode("\n", $sql);
        $cleaned = [];
        foreach ($lines as $line) {
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '--')) continue;
            $cleaned[] = $line;
        }
        $cleanSql = implode("\n", $cleaned);

        $statements = array_filter(
            array_map('trim', explode(';', $cleanSql)),
            fn(string $s) => $s !== ''
        );

        try {
            foreach ($statements as $stmt) {
                try {
                    $this->db->exec($stmt . ';');
                } catch (\Throwable $e) {
                    // Ignore: duplicate column (1060), duplicate key/index (1061),
                    // table already exists (1050) — safe to continue
                    $code = $e->getCode();
                    $msg  = $e->getMessage();
                    $ignorable = in_array((string)$code, ['1060','1061','1050','42S01'], true)
                        || str_contains($msg, 'Duplicate column')
                        || str_contains($msg, 'already exists')
                        || str_contains($msg, "Can't DROP");
                    if (!$ignorable) {
                        throw $e;
                    }
                }
            }

            // Mark as run
            $this->db->execute(
                "INSERT IGNORE INTO saas_migrations (migration, batch) VALUES (?, ?)",
                [$migration['name'], $batch]
            );

            return [
                'name'    => $migration['name'],
                'status'  => 'success',
                'message' => 'Erfolgreich ausgeführt.',
            ];
        } catch (\Throwable $e) {
            return [
                'name'    => $migration['name'],
                'status'  => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Ensures the saas_migrations tracking table exists.
     * Called before any migration check so fresh installs bootstrap correctly.
     */
    public function ensureTrackingTable(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS `saas_migrations` (
              `id`        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              `migration` VARCHAR(200) NOT NULL UNIQUE,
              `batch`     INT NOT NULL DEFAULT 1,
              `ran_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    private function runPhpMigration(array $migration, int $batch): array
    {
        try {
            $fn = require $migration['file'];
            if (!is_callable($fn)) {
                return [
                    'name'    => $migration['name'],
                    'status'  => 'error',
                    'message' => 'PHP-Migration muss eine callable zurückgeben.',
                ];
            }

            $fn($this->db);

            $this->db->execute(
                "INSERT IGNORE INTO saas_migrations (migration, batch) VALUES (?, ?)",
                [$migration['name'], $batch]
            );

            return [
                'name'    => $migration['name'],
                'status'  => 'success',
                'message' => 'PHP-Migration erfolgreich ausgeführt.',
            ];
        } catch (\Throwable $e) {
            return [
                'name'    => $migration['name'],
                'status'  => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Remove a migration from the tracking table so it can be re-run.
     */
    public function resetMigration(string $name): void
    {
        $this->db->execute("DELETE FROM saas_migrations WHERE migration = ?", [$name]);
    }

    private function getNextBatch(): int
    {
        $max = $this->db->fetchColumn("SELECT COALESCE(MAX(batch), 0) FROM saas_migrations");
        return (int)$max + 1;
    }
}

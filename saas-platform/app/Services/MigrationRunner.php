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
        $files = glob($this->migrationsPath . '/*.sql');
        if (!$files) return [];

        sort($files);

        return array_map(function (string $path): array {
            $name = pathinfo($path, PATHINFO_FILENAME);
            return [
                'name'    => $name,
                'file'    => $path,
                'ran'     => false,
                'ran_at'  => null,
                'batch'   => null,
            ];
        }, $files);
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
        $sql = file_get_contents($migration['file']);
        if (!$sql) {
            return [
                'name'    => $migration['name'],
                'status'  => 'error',
                'message' => 'SQL-Datei konnte nicht gelesen werden.',
            ];
        }

        // Split on semicolons, skip comments-only lines
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            fn(string $s) => $s !== '' && !str_starts_with(ltrim($s), '--')
        );

        try {
            foreach ($statements as $stmt) {
                $this->db->exec($stmt . ';');
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

    private function getNextBatch(): int
    {
        $max = $this->db->fetchColumn("SELECT COALESCE(MAX(batch), 0) FROM saas_migrations");
        return (int)$max + 1;
    }
}

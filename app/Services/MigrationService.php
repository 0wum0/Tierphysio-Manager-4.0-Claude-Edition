<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

class MigrationService
{
    public function __construct(
        private readonly Database $db
    ) {}

    public function getCurrentVersion(): int
    {
        try {
            $result = $this->db->fetchColumn(
                "SELECT value FROM settings WHERE `key` = 'db_version'"
            );
            return $result !== false ? (int)$result : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    public function getLatestVersion(): int
    {
        $files = $this->getMigrationFiles();
        if (empty($files)) return 0;
        $last = end($files);
        preg_match('/^(\d+)/', basename($last), $m);
        return isset($m[1]) ? (int)$m[1] : 0;
    }

    public function getPendingMigrations(): array
    {
        $current = $this->getCurrentVersion();
        $pending = [];

        foreach ($this->getMigrationFiles() as $file) {
            preg_match('/^(\d+)/', basename($file), $m);
            $version = isset($m[1]) ? (int)$m[1] : 0;
            if ($version > $current) {
                $pending[] = [
                    'version' => $version,
                    'file'    => basename($file),
                    'path'    => $file,
                ];
            }
        }

        return $pending;
    }

    public function runPending(): array
    {
        $pending = $this->getPendingMigrations();
        $ran     = [];

        foreach ($pending as $migration) {
            $this->runMigration($migration['path']);
            $this->setVersion($migration['version']);
            $ran[] = $migration['file'];
        }

        return $ran;
    }

    private function runMigration(string $file): void
    {
        $sql        = file_get_contents($file);
        $statements = array_filter(array_map('trim', explode(';', $sql)));

        $this->db->beginTransaction();
        try {
            foreach ($statements as $statement) {
                if (!empty($statement)) {
                    $this->db->execute($statement);
                }
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    private function setVersion(int $version): void
    {
        $this->db->execute(
            "INSERT INTO settings (`key`, value) VALUES ('db_version', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)",
            [(string)$version]
        );
    }

    private function getMigrationFiles(): array
    {
        $files = glob(MIGRATIONS_PATH . '/*.sql');
        if (!$files) return [];
        sort($files);
        return $files;
    }
}

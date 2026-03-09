<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

class UserPreferencesRepository
{
    public function __construct(private readonly Database $db) {}

    public function get(int $userId, string $key, mixed $default = null): mixed
    {
        $row = $this->db->fetch(
            "SELECT pref_value FROM {$this->db->t('user_preferences')} WHERE user_id = ? AND pref_key = ?",
            [$userId, $key]
        );
        return $row ? $row['pref_value'] : $default;
    }

    public function set(int $userId, string $key, string $value): void
    {
        $this->db->execute(
            "INSERT INTO {$this->db->t('user_preferences')} (user_id, pref_key, pref_value)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE pref_value = VALUES(pref_value)",
            [$userId, $key, $value]
        );
    }

    public function getAll(int $userId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT pref_key, pref_value FROM {$this->db->t('user_preferences')} WHERE user_id = ?",
            [$userId]
        );
        $result = [];
        foreach ($rows as $row) {
            $result[$row['pref_key']] = $row['pref_value'];
        }
        return $result;
    }
}

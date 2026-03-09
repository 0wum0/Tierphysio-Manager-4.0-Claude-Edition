<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Repository;

class UserRepository extends Repository
{
    protected string $table = 'users';

    public function __construct(Database $db)
    {
        parent::__construct($db);
    }

    public function findByEmail(string $email): array|false
    {
        return $this->findOneBy('email', $email);
    }

    public function updateLastLogin(int|string $id): void
    {
        $this->db->execute(
            "UPDATE {$this->db->t('users')} SET last_login = NOW() WHERE id = ?",
            [$id]
        );
    }

    public function findAll(string $orderBy = 'name', string $direction = 'ASC'): array
    {
        return $this->db->fetchAll(
            "SELECT id, name, email, role, active, last_login, created_at FROM {$this->db->t('users')} ORDER BY `{$orderBy}` {$direction}"
        );
    }

    public function findFirstAdmin(): array|false
    {
        return $this->db->fetch(
            "SELECT * FROM {$this->db->t('users')} WHERE role = 'admin' AND active = 1 ORDER BY id ASC LIMIT 1"
        );
    }
}

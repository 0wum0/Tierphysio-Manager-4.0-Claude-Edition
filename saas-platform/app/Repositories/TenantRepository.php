<?php

declare(strict_types=1);

namespace Saas\Repositories;

use Saas\Core\Database;

class TenantRepository
{
    public function __construct(private Database $db) {}

    public function all(int $limit = 100, int $offset = 0): array
    {
        return $this->db->fetchAll(
            "SELECT t.*, p.name AS plan_name, p.slug AS plan_slug
             FROM tenants t
             LEFT JOIN plans p ON p.id = t.plan_id
             ORDER BY t.created_at DESC
             LIMIT ? OFFSET ?",
            [$limit, $offset]
        );
    }

    public function count(): int
    {
        return (int)$this->db->fetchColumn("SELECT COUNT(*) FROM tenants");
    }

    public function countByStatus(string $status): int
    {
        return (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM tenants WHERE status = ?", [$status]
        );
    }

    public function find(int $id): array|false
    {
        return $this->db->fetch(
            "SELECT t.*, p.name AS plan_name, p.slug AS plan_slug,
                    p.features AS plan_features, p.max_users
             FROM tenants t
             LEFT JOIN plans p ON p.id = t.plan_id
             WHERE t.id = ?",
            [$id]
        );
    }

    public function findByUuid(string $uuid): array|false
    {
        return $this->db->fetch(
            "SELECT t.*, p.name AS plan_name, p.slug AS plan_slug,
                    p.features AS plan_features, p.max_users
             FROM tenants t
             LEFT JOIN plans p ON p.id = t.plan_id
             WHERE t.uuid = ?",
            [$uuid]
        );
    }

    public function findByEmail(string $email): array|false
    {
        return $this->db->fetch(
            "SELECT * FROM tenants WHERE email = ?", [$email]
        );
    }

    public function create(array $data): int
    {
        return (int)$this->db->insert(
            "INSERT INTO tenants (uuid, practice_name, owner_name, email, phone, address, city, zip, country, plan_id, status, trial_ends_at)
             VALUES (:uuid, :practice_name, :owner_name, :email, :phone, :address, :city, :zip, :country, :plan_id, :status, :trial_ends_at)",
            $data
        );
    }

    public function update(int $id, array $data): void
    {
        $sets   = [];
        $params = [];
        foreach ($data as $key => $value) {
            $sets[]        = "`{$key}` = :{$key}";
            $params[$key]  = $value;
        }
        $params['id'] = $id;
        $this->db->execute("UPDATE tenants SET " . implode(', ', $sets) . " WHERE id = :id", $params);
    }

    public function setStatus(int $id, string $status): void
    {
        $this->db->execute("UPDATE tenants SET status = ? WHERE id = ?", [$status, $id]);
    }

    public function setTablePrefix(int $id, string $tablePrefix): void
    {
        $this->db->execute(
            "UPDATE tenants SET table_prefix = ?, db_created = 1 WHERE id = ?",
            [$tablePrefix, $id]
        );
    }

    public function setAdminCreated(int $id): void
    {
        $this->db->execute("UPDATE tenants SET admin_created = 1 WHERE id = ?", [$id]);
    }

    public function delete(int $id): void
    {
        $this->db->execute("DELETE FROM tenants WHERE id = ?", [$id]);
    }

    public function search(string $term): array
    {
        $like = "%{$term}%";
        return $this->db->fetchAll(
            "SELECT t.*, p.name AS plan_name
             FROM tenants t
             LEFT JOIN plans p ON p.id = t.plan_id
             WHERE t.practice_name LIKE ? OR t.owner_name LIKE ? OR t.email LIKE ?
             ORDER BY t.created_at DESC
             LIMIT 50",
            [$like, $like, $like]
        );
    }

    public function getStats(): array
    {
        return [
            'total'     => $this->count(),
            'active'    => $this->countByStatus('active'),
            'pending'   => $this->countByStatus('pending'),
            'paused'    => $this->countByStatus('paused'),
            'cancelled' => $this->countByStatus('cancelled'),
            'suspended' => $this->countByStatus('suspended'),
        ];
    }
}

<?php

declare(strict_types=1);

namespace Plugins\PatientInvite;

use App\Core\Database;

class InviteRepository
{
    private const TABLE = 'patient_invite_tokens';

    public function __construct(private readonly Database $db) {}

    public function create(array $data): int
    {
        $cols         = implode(', ', array_map(fn($k) => "`$k`", array_keys($data)));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $this->db->execute(
            "INSERT INTO `" . self::TABLE . "` ($cols) VALUES ($placeholders)",
            array_values($data)
        );
        return (int)$this->db->lastInsertId();
    }

    public function findByToken(string $token): array|false
    {
        return $this->db->fetch(
            "SELECT * FROM `" . self::TABLE . "` WHERE token = ? LIMIT 1",
            [$token]
        );
    }

    public function findById(int $id): array|false
    {
        return $this->db->fetch(
            "SELECT * FROM `" . self::TABLE . "` WHERE id = ? LIMIT 1",
            [$id]
        );
    }

    public function getPaginated(int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;
        $total  = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM `" . self::TABLE . "`"
        );
        $items = $this->db->fetchAll(
            "SELECT t.*, u.name AS created_by_name
             FROM `" . self::TABLE . "` t
             LEFT JOIN users u ON t.created_by = u.id
             ORDER BY t.created_at DESC
             LIMIT ? OFFSET ?",
            [$perPage, $offset]
        );
        return [
            'items'        => $items,
            'total'        => $total,
            'current_page' => $page,
            'last_page'    => max(1, (int)ceil($total / $perPage)),
        ];
    }

    public function accept(string $token, int $patientId, int $ownerId): void
    {
        $this->db->execute(
            "UPDATE `" . self::TABLE . "`
             SET status = 'angenommen',
                 accepted_at = NOW(),
                 accepted_patient_id = ?,
                 accepted_owner_id = ?
             WHERE token = ?",
            [$patientId, $ownerId, $token]
        );
    }

    public function expireOld(): void
    {
        $this->db->execute(
            "UPDATE `" . self::TABLE . "`
             SET status = 'abgelaufen'
             WHERE status = 'offen' AND expires_at < NOW()"
        );
    }

    public function countByStatus(string $status): int
    {
        return (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM `" . self::TABLE . "` WHERE status = ?",
            [$status]
        );
    }

    public function isTokenValid(string $token): bool
    {
        $row = $this->findByToken($token);
        if (!$row) return false;
        if ($row['status'] !== 'offen') return false;
        if (strtotime($row['expires_at']) < time()) return false;
        return true;
    }
}

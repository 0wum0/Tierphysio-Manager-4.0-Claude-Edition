<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

class TreatmentTypeRepository
{
    public function __construct(private readonly Database $db) {}

    public function findAll(): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM treatment_types ORDER BY sort_order ASC, name ASC'
        );
    }

    public function findActive(): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM treatment_types WHERE active = 1 ORDER BY sort_order ASC, name ASC'
        );
    }

    public function findById(int $id): array|false
    {
        return $this->db->fetchOne(
            'SELECT * FROM treatment_types WHERE id = ?', [$id]
        );
    }

    public function create(array $data): int
    {
        $this->db->execute(
            'INSERT INTO treatment_types (name, color, price, description, active, sort_order)
             VALUES (:name, :color, :price, :description, :active, :sort_order)',
            [
                ':name'        => $data['name'],
                ':color'       => $data['color']       ?? '#4f7cff',
                ':price'       => isset($data['price']) && $data['price'] !== '' ? $data['price'] : null,
                ':description' => $data['description'] ?? null,
                ':active'      => $data['active']      ?? 1,
                ':sort_order'  => $data['sort_order']  ?? 0,
            ]
        );
        return (int)$this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $this->db->execute(
            'UPDATE treatment_types SET name=:name, color=:color, price=:price,
             description=:description, active=:active, sort_order=:sort_order
             WHERE id=:id',
            [
                ':name'        => $data['name'],
                ':color'       => $data['color']       ?? '#4f7cff',
                ':price'       => isset($data['price']) && $data['price'] !== '' ? $data['price'] : null,
                ':description' => $data['description'] ?? null,
                ':active'      => $data['active']      ?? 1,
                ':sort_order'  => $data['sort_order']  ?? 0,
                ':id'          => $id,
            ]
        );
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM treatment_types WHERE id = ?', [$id]);
    }
}

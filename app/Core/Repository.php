<?php

declare(strict_types=1);

namespace App\Core;

abstract class Repository
{
    protected Database $db;
    protected string $table;
    protected string $primaryKey = 'id';

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function findById(int|string $id): array|false
    {
        return $this->db->fetch(
            "SELECT * FROM `{$this->table}` WHERE `{$this->primaryKey}` = ? LIMIT 1",
            [$id]
        );
    }

    public function findAll(string $orderBy = '', string $direction = 'ASC'): array
    {
        $order = $orderBy ? "ORDER BY `{$orderBy}` {$direction}" : '';
        return $this->db->fetchAll("SELECT * FROM `{$this->table}` {$order}");
    }

    public function findBy(string $column, mixed $value): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM `{$this->table}` WHERE `{$column}` = ?",
            [$value]
        );
    }

    public function findOneBy(string $column, mixed $value): array|false
    {
        return $this->db->fetch(
            "SELECT * FROM `{$this->table}` WHERE `{$column}` = ? LIMIT 1",
            [$value]
        );
    }

    public function count(string $where = '', array $params = []): int
    {
        $sql = "SELECT COUNT(*) FROM `{$this->table}`";
        if ($where) {
            $sql .= " WHERE {$where}";
        }
        return (int)$this->db->fetchColumn($sql, $params);
    }

    public function create(array $data): string
    {
        $columns = implode('`, `', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql = "INSERT INTO `{$this->table}` (`{$columns}`) VALUES ({$placeholders})";
        return $this->db->insert($sql, array_values($data));
    }

    public function update(int|string $id, array $data): int
    {
        $sets = implode(' = ?, ', array_map(fn($k) => "`{$k}`", array_keys($data))) . ' = ?';
        $sql  = "UPDATE `{$this->table}` SET {$sets} WHERE `{$this->primaryKey}` = ?";
        return $this->db->execute($sql, [...array_values($data), $id]);
    }

    public function delete(int|string $id): int
    {
        return $this->db->execute(
            "DELETE FROM `{$this->table}` WHERE `{$this->primaryKey}` = ?",
            [$id]
        );
    }

    public function paginate(int $page, int $perPage, string $where = '', array $params = [], string $orderBy = '', string $direction = 'ASC'): array
    {
        $offset = ($page - 1) * $perPage;
        $total  = $this->count($where, $params);
        $order  = $orderBy ? "ORDER BY `{$orderBy}` {$direction}" : '';
        $whereClause = $where ? "WHERE {$where}" : '';

        $items = $this->db->fetchAll(
            "SELECT * FROM `{$this->table}` {$whereClause} {$order} LIMIT ? OFFSET ?",
            [...$params, $perPage, $offset]
        );

        return [
            'items'       => $items,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'last_page'   => (int)ceil($total / $perPage),
            'has_next'    => ($page * $perPage) < $total,
            'has_prev'    => $page > 1,
        ];
    }
}

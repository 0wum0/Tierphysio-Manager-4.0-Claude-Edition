<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Repository;

class OwnerRepository extends Repository
{
    protected string $table = 'owners';

    public function __construct(Database $db)
    {
        parent::__construct($db);
    }

    public function getPaginated(int $page, int $perPage, string $search = ''): array
    {
        $where  = '';
        $params = [];

        if (!empty($search)) {
            $where  = "WHERE first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR phone LIKE ?";
            $params = ["%{$search}%", "%{$search}%", "%{$search}%", "%{$search}%"];
        }

        $t_owners   = $this->db->t('owners');
        $t_patients = $this->db->t('patients');
        $total  = (int)$this->db->fetchColumn("SELECT COUNT(*) FROM {$t_owners} {$where}", $params);
        $offset = ($page - 1) * $perPage;

        $items = $this->db->fetchAll(
            "SELECT o.*, COUNT(p.id) AS animal_count
             FROM {$t_owners} o
             LEFT JOIN {$t_patients} p ON p.owner_id = o.id
             {$where}
             GROUP BY o.id
             ORDER BY o.last_name ASC, o.first_name ASC
             LIMIT ? OFFSET ?",
            [...$params, $perPage, $offset]
        );

        return [
            'items'     => $items,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => (int)ceil($total / $perPage),
            'has_next'  => ($page * $perPage) < $total,
            'has_prev'  => $page > 1,
        ];
    }

    public function findAll(string $orderBy = 'last_name', string $direction = 'ASC'): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM {$this->db->t('owners')} ORDER BY `{$orderBy}` {$direction}, first_name {$direction}"
        );
    }
}

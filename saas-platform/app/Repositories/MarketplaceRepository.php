<?php

declare(strict_types=1);

namespace Saas\Repositories;

use Saas\Core\Database;

class MarketplaceRepository
{
    public function __construct(private Database $db) {}

    public function allPlugins(bool $activeOnly = true): array
    {
        $where = $activeOnly ? 'WHERE is_active = 1' : '';
        return $this->db->fetchAll(
            "SELECT * FROM marketplace_plugins {$where} ORDER BY is_featured DESC, sort_order ASC"
        );
    }

    public function findPlugin(int $id): array|false
    {
        return $this->db->fetch("SELECT * FROM marketplace_plugins WHERE id = ?", [$id]);
    }

    public function findPluginBySlug(string $slug): array|false
    {
        return $this->db->fetch("SELECT * FROM marketplace_plugins WHERE slug = ?", [$slug]);
    }

    public function createPlugin(array $data): int
    {
        return (int)$this->db->insert(
            "INSERT INTO marketplace_plugins
             (slug, name, description, long_desc, category, icon, price, price_type, is_active, is_featured, version, screenshots, requirements, sort_order)
             VALUES (:slug, :name, :description, :long_desc, :category, :icon, :price, :price_type, :is_active, :is_featured, :version, :screenshots, :requirements, :sort_order)",
            $data
        );
    }

    public function updatePlugin(int $id, array $data): void
    {
        $sets   = [];
        $params = [];
        foreach ($data as $key => $value) {
            $sets[]       = "`{$key}` = :{$key}";
            $params[$key] = $value;
        }
        $params['id'] = $id;
        $this->db->execute(
            "UPDATE marketplace_plugins SET " . implode(', ', $sets) . " WHERE id = :id",
            $params
        );
    }

    public function deletePlugin(int $id): void
    {
        $this->db->execute("DELETE FROM marketplace_plugins WHERE id = ?", [$id]);
    }

    public function getPurchase(int $tenantId, int $pluginId): array|false
    {
        return $this->db->fetch(
            "SELECT * FROM marketplace_purchases WHERE tenant_id = ? AND plugin_id = ? AND status = 'active'",
            [$tenantId, $pluginId]
        );
    }

    public function getActivePurchasesForTenant(int $tenantId): array
    {
        return $this->db->fetchAll(
            "SELECT mp.*, p.slug AS plugin_slug, p.name AS plugin_name, p.icon AS plugin_icon, p.category
             FROM marketplace_purchases mp
             JOIN marketplace_plugins p ON p.id = mp.plugin_id
             WHERE mp.tenant_id = ? AND mp.status = 'active'
             ORDER BY mp.activated_at DESC",
            [$tenantId]
        );
    }

    public function getAllPurchases(int $limit = 100, int $offset = 0): array
    {
        return $this->db->fetchAll(
            "SELECT mp.*, p.name AS plugin_name, t.practice_name, t.email AS tenant_email
             FROM marketplace_purchases mp
             JOIN marketplace_plugins p ON p.id = mp.plugin_id
             JOIN tenants t ON t.id = mp.tenant_id
             ORDER BY mp.created_at DESC
             LIMIT ? OFFSET ?",
            [$limit, $offset]
        );
    }

    public function createPurchase(array $data): int
    {
        return (int)$this->db->insert(
            "INSERT INTO marketplace_purchases
             (tenant_id, plugin_id, status, payment_method, payment_ref, amount_paid, expires_at)
             VALUES (:tenant_id, :plugin_id, :status, :payment_method, :payment_ref, :amount_paid, :expires_at)",
            $data
        );
    }

    public function cancelPurchase(int $tenantId, int $pluginId): void
    {
        $this->db->execute(
            "UPDATE marketplace_purchases SET status = 'cancelled' WHERE tenant_id = ? AND plugin_id = ?",
            [$tenantId, $pluginId]
        );
    }

    public function tenantHasPlugin(int $tenantId, int $pluginId): bool
    {
        return (bool)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM marketplace_purchases
             WHERE tenant_id = ? AND plugin_id = ? AND status = 'active'
             AND (expires_at IS NULL OR expires_at > NOW())",
            [$tenantId, $pluginId]
        );
    }

    public function countPurchasesForPlugin(int $pluginId): int
    {
        return (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM marketplace_purchases WHERE plugin_id = ? AND status = 'active'",
            [$pluginId]
        );
    }

    public function getRevenueForPlugin(int $pluginId): float
    {
        return (float)$this->db->fetchColumn(
            "SELECT COALESCE(SUM(amount_paid), 0) FROM marketplace_purchases WHERE plugin_id = ?",
            [$pluginId]
        );
    }

    public function getTotalRevenue(): float
    {
        return (float)$this->db->fetchColumn(
            "SELECT COALESCE(SUM(amount_paid), 0) FROM marketplace_purchases WHERE status = 'active'"
        );
    }
}

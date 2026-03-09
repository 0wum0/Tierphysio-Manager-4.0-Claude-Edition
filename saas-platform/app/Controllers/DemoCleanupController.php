<?php

declare(strict_types=1);

namespace Saas\Controllers;

use Saas\Core\Controller;
use Saas\Core\View;
use Saas\Core\Session;
use Saas\Core\Database;

class DemoCleanupController extends Controller
{
    public function __construct(
        View            $view,
        Session         $session,
        private Database $db
    ) {
        parent::__construct($view, $session);
    }

    /**
     * Called via cron: GET /api/demo/cleanup?secret=YOUR_CRON_SECRET
     * Or manually from admin: POST /admin/demo/cleanup
     */
    public function run(array $params = []): void
    {
        // Allow both cron (GET with secret) and admin POST
        $isAdmin = $this->session->has('saas_user_id');
        $cronSecret = $_ENV['CRON_SECRET'] ?? '';
        $providedSecret = $_GET['secret'] ?? '';

        if (!$isAdmin && (!$cronSecret || $providedSecret !== $cronSecret)) {
            http_response_code(403);
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }

        $deleted = $this->cleanupExpiredDemos();

        if ($isAdmin) {
            $this->verifyCsrf();
            $this->session->flash('success', "Demo-Cleanup: {$deleted} abgelaufene Demo(s) gelöscht.");
            $this->redirect('/admin/tenants');
        }

        header('Content-Type: application/json');
        echo json_encode(['deleted' => $deleted, 'ok' => true]);
        exit;
    }

    /**
     * Auto-cleanup hook: called on every admin page load (lightweight check).
     * Only runs actual cleanup if there are expired demos.
     */
    public static function autoCleanup(Database $db): void
    {
        try {
            // Quick check first — avoid heavy queries on every request
            $count = $db->fetchColumn(
                "SELECT COUNT(*) FROM tenants
                 WHERE is_demo = 1 AND demo_expires_at IS NOT NULL AND demo_expires_at < NOW()"
            );
            if ((int)$count > 0) {
                self::doCleanup($db);
            }
        } catch (\Throwable) {
            // Silently ignore if demo columns don't exist yet
        }
    }

    private function cleanupExpiredDemos(): int
    {
        return self::doCleanup($this->db);
    }

    private static function doCleanup(Database $db): int
    {
        // Get expired demo tenant IDs
        $expired = $db->fetchAll(
            "SELECT id, email, table_prefix FROM tenants
             WHERE is_demo = 1 AND demo_expires_at IS NOT NULL AND demo_expires_at < NOW()"
        );

        if (empty($expired)) return 0;

        $count = 0;
        foreach ($expired as $tenant) {
            $tenantId = (int)$tenant['id'];

            // Remove marketplace purchases
            try {
                $db->execute("DELETE FROM marketplace_purchases WHERE tenant_id = ?", [$tenantId]);
            } catch (\Throwable) {}

            // Remove subscriptions
            try {
                $db->execute("DELETE FROM subscriptions WHERE tenant_id = ?", [$tenantId]);
            } catch (\Throwable) {}

            // Remove the demo admin account
            try {
                $db->execute("DELETE FROM saas_admins WHERE email = ?", [$tenant['email']]);
            } catch (\Throwable) {}

            // Delete the tenant
            $db->execute("DELETE FROM tenants WHERE id = ?", [$tenantId]);

            $count++;
        }

        return $count;
    }
}

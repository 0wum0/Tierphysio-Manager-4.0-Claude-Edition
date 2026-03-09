<?php

declare(strict_types=1);

namespace Saas\Controllers;

use Saas\Core\Controller;
use Saas\Core\View;
use Saas\Core\Session;
use Saas\Repositories\MarketplaceRepository;
use Saas\Repositories\TenantRepository;
use Saas\Core\Config;

class AdminMarketplaceController extends Controller
{
    public function __construct(
        View                          $view,
        Session                       $session,
        private MarketplaceRepository $marketplaceRepo,
        private TenantRepository      $tenantRepo,
        private Config                $config
    ) {
        parent::__construct($view, $session);
    }

    public function index(array $params = []): void
    {
        $this->requireAuth();

        $plugins     = [];
        $purchases   = [];
        $revenue     = 0.0;
        $needsUpdate = false;

        try {
            $plugins   = $this->marketplaceRepo->allPlugins(false);
            $purchases = $this->marketplaceRepo->getAllPurchases(50);
            $revenue   = $this->marketplaceRepo->getTotalRevenue();

            foreach ($plugins as &$p) {
                $p['purchase_count'] = $this->marketplaceRepo->countPurchasesForPlugin((int)$p['id']);
                $p['revenue']        = $this->marketplaceRepo->getRevenueForPlugin((int)$p['id']);
            }
            unset($p);
        } catch (\Throwable $e) {
            $needsUpdate = true;
            $needsUpdateReason = $e->getMessage();
        }

        $this->render('admin/marketplace/index.twig', [
            'needs_update'        => $needsUpdate,
            'needs_update_reason' => $needsUpdateReason ?? null,
            'page_title' => 'Marktplatz verwalten',
            'active_nav' => 'marketplace_admin',
            'plugins'    => $plugins,
            'purchases'  => $purchases,
            'revenue'    => $revenue,
        ]);
    }

    public function createForm(array $params = []): void
    {
        $this->requireAuth();
        $this->render('admin/marketplace/form.twig', [
            'page_title' => 'Plugin hinzufügen',
            'active_nav' => 'marketplace_admin',
            'plugin'     => null,
        ]);
    }

    public function create(array $params = []): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $this->marketplaceRepo->createPlugin($this->buildPluginData());
        $this->session->flash('success', 'Plugin erstellt.');
        $this->redirect('/admin/marketplace');
    }

    public function editForm(array $params = []): void
    {
        $this->requireAuth();

        $plugin = $this->marketplaceRepo->findPlugin((int)($params['id'] ?? 0));
        if (!$plugin) { $this->notFound(); }

        $this->render('admin/marketplace/form.twig', [
            'page_title' => 'Plugin bearbeiten: ' . $plugin['name'],
            'active_nav' => 'marketplace_admin',
            'plugin'     => $plugin,
        ]);
    }

    public function update(array $params = []): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $plugin = $this->marketplaceRepo->findPlugin((int)($params['id'] ?? 0));
        if (!$plugin) { $this->notFound(); }

        $this->marketplaceRepo->updatePlugin((int)$plugin['id'], $this->buildPluginData());
        $this->session->flash('success', 'Plugin aktualisiert.');
        $this->redirect('/admin/marketplace');
    }

    public function delete(array $params = []): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $this->marketplaceRepo->deletePlugin((int)($params['id'] ?? 0));
        $this->session->flash('success', 'Plugin gelöscht.');
        $this->redirect('/admin/marketplace');
    }

    public function grantManual(array $params = []): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $tenantId = (int)$this->post('tenant_id');
        $pluginId = (int)($params['id'] ?? 0);
        $plugin   = $this->marketplaceRepo->findPlugin($pluginId);

        if (!$plugin || !$tenantId) { $this->notFound(); }

        if (!$this->marketplaceRepo->tenantHasPlugin($tenantId, $pluginId)) {
            $this->marketplaceRepo->createPurchase([
                'tenant_id'      => $tenantId,
                'plugin_id'      => $pluginId,
                'status'         => 'active',
                'payment_method' => 'manual',
                'payment_ref'    => 'admin-grant',
                'amount_paid'    => 0.00,
                'expires_at'     => null,
            ]);
            $this->session->flash('success', 'Plugin manuell freigeschaltet.');
        } else {
            $this->session->flash('info', 'Tenant hat Plugin bereits.');
        }

        $redirect = $this->post('redirect', '/admin/marketplace');
        $this->redirect($redirect);
    }

    public function revokeManual(array $params = []): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $tenantId = (int)$this->post('tenant_id');
        $pluginId = (int)($params['id'] ?? 0);

        $this->marketplaceRepo->cancelPurchase($tenantId, $pluginId);
        $this->session->flash('success', 'Plugin-Zugang entzogen.');

        $redirect = $this->post('redirect', '/admin/marketplace');
        $this->redirect($redirect);
    }

    public function toggle(array $params = []): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $tenantId = (int)$this->post('tenant_id');
        $pluginId = (int)($params['id'] ?? 0);
        $enabled  = (bool)(int)$this->post('enabled', 0);

        if (!$tenantId || !$pluginId) { $this->notFound(); }

        $this->marketplaceRepo->togglePlugin($tenantId, $pluginId, $enabled);
        $this->session->flash('success', 'Plugin ' . ($enabled ? 'aktiviert' : 'deaktiviert') . '.');

        $redirect = $this->post('redirect', '/admin/marketplace');
        $this->redirect($redirect);
    }

    public function tenantPlugins(array $params = []): void
    {
        $this->requireAuth();

        $tenantId = (int)($params['id'] ?? 0);
        $tenant   = $this->tenantRepo->findById($tenantId);
        if (!$tenant) { $this->notFound(); }

        $allPlugins = [];
        $purchases  = [];
        $needsUpdate = false;

        try {
            $allPlugins = $this->marketplaceRepo->allPlugins(true);
            $purchases  = $this->marketplaceRepo->getPurchasesForTenantWithDetails($tenantId);
        } catch (\Throwable) {
            $needsUpdate = true;
        }

        // Map purchases by plugin_id for quick lookup
        $purchaseMap = [];
        foreach ($purchases as $p) {
            $purchaseMap[(int)$p['plugin_id']] = $p;
        }

        // Merge: mark which plugins tenant has / enabled
        foreach ($allPlugins as &$plugin) {
            $pid = (int)$plugin['id'];
            if (isset($purchaseMap[$pid])) {
                $plugin['purchase']       = $purchaseMap[$pid];
                $plugin['tenant_has']     = true;
                $plugin['plugin_enabled'] = (bool)$purchaseMap[$pid]['plugin_enabled'];
            } else {
                $plugin['purchase']       = null;
                $plugin['tenant_has']     = false;
                $plugin['plugin_enabled'] = false;
            }
        }
        unset($plugin);

        $this->render('admin/marketplace/tenant_plugins.twig', [
            'page_title'  => 'Plugins: ' . $tenant['practice_name'],
            'active_nav'  => 'tenants',
            'tenant'      => $tenant,
            'plugins'     => $allPlugins,
            'needs_update'=> $needsUpdate,
        ]);
    }

    private function buildPluginData(): array
    {
        $screenshots = array_filter(array_map('trim', explode("\n", $this->post('screenshots', ''))));
        $requirements = array_filter(array_map('trim', explode("\n", $this->post('requirements', ''))));

        return [
            'slug'         => trim($this->post('slug', '')),
            'name'         => trim($this->post('name', '')),
            'description'  => trim($this->post('description', '')),
            'long_desc'    => trim($this->post('long_desc', '')),
            'category'     => trim($this->post('category', 'Allgemein')),
            'icon'         => trim($this->post('icon', 'bi-puzzle')),
            'price'        => (float)str_replace(',', '.', $this->post('price', '0')),
            'price_type'   => $this->post('price_type', 'one_time'),
            'is_active'    => (int)(bool)$this->post('is_active', 0),
            'is_featured'  => (int)(bool)$this->post('is_featured', 0),
            'version'      => trim($this->post('version', '1.0.0')),
            'screenshots'  => json_encode(array_values($screenshots)),
            'requirements' => json_encode(array_values($requirements)),
            'sort_order'   => (int)$this->post('sort_order', 0),
        ];
    }
}

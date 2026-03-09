<?php

declare(strict_types=1);

namespace Saas\Controllers;

use Saas\Core\Controller;
use Saas\Core\View;
use Saas\Core\Session;
use Saas\Repositories\MarketplaceRepository;
use Saas\Repositories\TenantRepository;

class AdminMarketplaceController extends Controller
{
    public function __construct(
        View                          $view,
        Session                       $session,
        private MarketplaceRepository $marketplaceRepo,
        private TenantRepository      $tenantRepo
    ) {
        parent::__construct($view, $session);
    }

    public function index(array $params = []): void
    {
        $this->requireAuth();

        $plugins  = $this->marketplaceRepo->allPlugins(false);
        $purchases = $this->marketplaceRepo->getAllPurchases(50);
        $revenue  = $this->marketplaceRepo->getTotalRevenue();

        foreach ($plugins as &$p) {
            $p['purchase_count'] = $this->marketplaceRepo->countPurchasesForPlugin((int)$p['id']);
            $p['revenue']        = $this->marketplaceRepo->getRevenueForPlugin((int)$p['id']);
        }
        unset($p);

        $this->render('admin/marketplace/index.twig', [
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

        $this->redirect('/admin/marketplace');
    }

    public function revokeManual(array $params = []): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $tenantId = (int)$this->post('tenant_id');
        $pluginId = (int)($params['id'] ?? 0);

        $this->marketplaceRepo->cancelPurchase($tenantId, $pluginId);
        $this->session->flash('success', 'Plugin-Zugang entzogen.');
        $this->redirect('/admin/marketplace');
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

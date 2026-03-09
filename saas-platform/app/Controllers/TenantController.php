<?php

declare(strict_types=1);

namespace Saas\Controllers;

use Saas\Core\Controller;
use Saas\Core\View;
use Saas\Core\Session;
use Saas\Repositories\TenantRepository;
use Saas\Repositories\SubscriptionRepository;
use Saas\Repositories\PlanRepository;
use Saas\Repositories\LicenseRepository;
use Saas\Services\TenantProvisioningService;
use Saas\Services\LicenseService;
use Saas\Core\Config;

class TenantController extends Controller
{
    public function __construct(
        View                          $view,
        Session                       $session,
        private TenantRepository       $tenantRepo,
        private SubscriptionRepository $subRepo,
        private PlanRepository         $planRepo,
        private LicenseRepository      $licenseRepo,
        private TenantProvisioningService $provisioningService,
        private LicenseService         $licenseService,
        private Config                 $config
    ) {
        parent::__construct($view, $session);
    }

    public function index(array $params = []): void
    {
        $this->requireAuth();

        $search  = trim($this->get('q', ''));
        $page    = max(1, (int)$this->get('page', 1));
        $perPage = 25;
        $offset  = ($page - 1) * $perPage;

        if ($search !== '') {
            $tenants = $this->tenantRepo->search($search);
            $total   = count($tenants);
        } else {
            $tenants = $this->tenantRepo->all($perPage, $offset);
            $total   = $this->tenantRepo->count();
        }

        // Attach practice_url to each tenant
        foreach ($tenants as &$t) {
            $t['practice_url'] = $this->buildPracticeUrl($t);
        }
        unset($t);

        $this->render('admin/tenants/index.twig', [
            'tenants'    => $tenants,
            'total'      => $total,
            'page'       => $page,
            'per_page'   => $perPage,
            'pages'      => (int)ceil($total / $perPage),
            'search'     => $search,
            'page_title' => 'Praxen verwalten',
        ]);
    }

    public function show(array $params = []): void
    {
        $this->requireAuth();

        $tenant = $this->tenantRepo->find((int)($params['id'] ?? 0));
        if (!$tenant) {
            $this->notFound();
        }

        $subscription = $this->subRepo->findByTenant((int)$tenant['id']);
        $allSubs      = $this->subRepo->allByTenant((int)$tenant['id']);
        $licenses     = $this->licenseRepo->getActiveForTenant((int)$tenant['id']);
        $plans        = $this->planRepo->allActive();

        $this->render('admin/tenants/show.twig', [
            'tenant'        => $tenant,
            'subscription'  => $subscription,
            'all_subs'      => $allSubs,
            'licenses'      => $licenses,
            'plans'         => $plans,
            'practice_url'  => $this->buildPracticeUrl($tenant),
            'page_title'    => $tenant['practice_name'],
        ]);
    }

    public function createForm(array $params = []): void
    {
        $this->requireAuth();

        $plans = $this->planRepo->allActive();
        $this->render('admin/tenants/create.twig', [
            'plans'      => $plans,
            'page_title' => 'Neue Praxis erstellen',
        ]);
    }

    public function create(array $params = []): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $data = [
            'practice_name'       => trim($this->post('practice_name', '')),
            'owner_name'          => trim($this->post('owner_name', '')),
            'email'               => strtolower(trim($this->post('email', ''))),
            'phone'               => trim($this->post('phone', '')),
            'address'             => trim($this->post('address', '')),
            'city'                => trim($this->post('city', '')),
            'zip'                 => trim($this->post('zip', '')),
            'country'             => $this->post('country', 'DE'),
            'plan_slug'           => $this->post('plan_slug', 'basic'),
            'billing_cycle'       => $this->post('billing_cycle', 'monthly'),
            'admin_password'      => $this->post('admin_password', ''),
            'payment_method'      => $this->post('payment_method', 'manual'),
        ];

        $errors = $this->validateTenantData($data);
        if ($errors) {
            $this->session->flash('error', implode('<br>', $errors));
            $this->redirect('/admin/tenants/create');
        }

        // Auto-generate password if not provided
        if (empty($data['admin_password'])) {
            $data['admin_password'] = $this->generatePassword();
        }

        try {
            $result = $this->provisioningService->provision($data);
            $this->session->flash('success',
                "Praxis '{$data['practice_name']}' erfolgreich erstellt! " .
                "Admin-Passwort: {$result['admin_password']} (wurde per E-Mail gesendet)"
            );
            $this->redirect('/admin/tenants/' . $result['tenant_id']);
        } catch (\Throwable $e) {
            $this->session->flash('error', 'Fehler beim Erstellen: ' . $e->getMessage());
            $this->redirect('/admin/tenants/create');
        }
    }

    public function editForm(array $params = []): void
    {
        $this->requireAuth();

        $tenant = $this->tenantRepo->find((int)($params['id'] ?? 0));
        if (!$tenant) {
            $this->notFound();
        }

        $plans = $this->planRepo->allActive();
        $this->render('admin/tenants/edit.twig', [
            'tenant'     => $tenant,
            'plans'      => $plans,
            'page_title' => 'Praxis bearbeiten: ' . $tenant['practice_name'],
        ]);
    }

    public function edit(array $params = []): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $id     = (int)($params['id'] ?? 0);
        $tenant = $this->tenantRepo->find($id);
        if (!$tenant) {
            $this->notFound();
        }

        $planSlug = $this->post('plan_slug', '');
        $plan     = $this->planRepo->findBySlug($planSlug);

        $this->tenantRepo->update($id, [
            'practice_name' => trim($this->post('practice_name', $tenant['practice_name'])),
            'owner_name'    => trim($this->post('owner_name', $tenant['owner_name'])),
            'phone'         => trim($this->post('phone', '')),
            'address'       => trim($this->post('address', '')),
            'city'          => trim($this->post('city', '')),
            'zip'           => trim($this->post('zip', '')),
            'country'       => $this->post('country', 'DE'),
            'plan_id'       => $plan ? (int)$plan['id'] : (int)$tenant['plan_id'],
            'notes'         => trim($this->post('notes', '')),
        ]);

        $this->session->flash('success', 'Praxis-Daten aktualisiert.');
        $this->redirect('/admin/tenants/' . $id);
    }

    public function suspend(array $params = []): void
    {
        $this->requireRole('superadmin', 'admin');
        $this->verifyCsrf();

        $id = (int)($params['id'] ?? 0);
        $this->provisioningService->suspend($id);
        $this->session->flash('success', 'Praxis gesperrt.');
        $this->redirect('/admin/tenants/' . $id);
    }

    public function reactivate(array $params = []): void
    {
        $this->requireRole('superadmin', 'admin');
        $this->verifyCsrf();

        $id = (int)($params['id'] ?? 0);
        $this->provisioningService->reactivate($id);
        $this->session->flash('success', 'Praxis reaktiviert.');
        $this->redirect('/admin/tenants/' . $id);
    }

    public function cancel(array $params = []): void
    {
        $this->requireRole('superadmin', 'admin');
        $this->verifyCsrf();

        $id = (int)($params['id'] ?? 0);
        $this->provisioningService->cancel($id);
        $this->session->flash('success', 'Abo gekündigt.');
        $this->redirect('/admin/tenants/' . $id);
    }

    public function issueLicense(array $params = []): void
    {
        $this->requireRole('superadmin', 'admin');
        $this->verifyCsrf();

        $id = (int)($params['id'] ?? 0);
        try {
            $token = $this->licenseService->issueToken($id);
            $this->session->flash('success', 'Neuer Lizenz-Token ausgestellt.');
        } catch (\Throwable $e) {
            $this->session->flash('error', 'Fehler: ' . $e->getMessage());
        }
        $this->redirect('/admin/tenants/' . $id);
    }

    public function delete(array $params = []): void
    {
        $this->requireRole('superadmin');
        $this->verifyCsrf();

        $id     = (int)($params['id'] ?? 0);
        $tenant = $this->tenantRepo->find($id);
        if (!$tenant) {
            $this->notFound();
        }

        $this->licenseService->revokeAllTokens($id);
        $this->tenantRepo->delete($id);

        $this->session->flash('success', "Praxis '{$tenant['practice_name']}' wurde gelöscht.");
        $this->redirect('/admin/tenants');
    }

    /**
     * Impersonate: generate a one-time admin-login token, store it in the
     * tenant's prefixed settings table, then redirect to their Praxissoftware
     * with the token in the URL so they get auto-logged-in as admin.
     */
    public function impersonate(array $params = []): void
    {
        $this->requireRole('superadmin', 'admin');

        $id     = (int)($params['id'] ?? 0);
        $tenant = $this->tenantRepo->find($id);
        if (!$tenant || empty($tenant['table_prefix'])) {
            $this->session->flash('error', 'Tenant nicht gefunden oder keine Tabellen angelegt.');
            $this->redirect('/admin/tenants/' . $id);
        }

        $prefix  = $tenant['table_prefix'];
        $token   = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + 300); // 5 minutes

        // Store token in tenant's settings table
        try {
            $pdo = $this->getTenantPdo();
            $pdo->prepare(
                "INSERT INTO `{$prefix}settings` (`key`, `value`) VALUES ('_impersonate_token', ?)
                 ON DUPLICATE KEY UPDATE `value` = ?"
            )->execute([$token . '|' . $expires, $token . '|' . $expires]);
        } catch (\Throwable $e) {
            $this->session->flash('error', 'Impersonation fehlgeschlagen: ' . $e->getMessage());
            $this->redirect('/admin/tenants/' . $id);
        }

        $practiceUrl = $this->buildPracticeUrl($tenant);
        $loginUrl    = rtrim($practiceUrl, '/') . '/admin-login?token=' . urlencode($token);

        header('Location: ' . $loginUrl);
        exit;
    }

    /**
     * Build the subdomain-based practice URL for a tenant.
     * e.g. subdomain="tpm5" → https://tpm5.tp.makeit.uno
     */
    private function buildPracticeUrl(array $tenant): string
    {
        if (empty($tenant['subdomain'])) {
            return '';
        }

        $appUrl   = $this->config->get('app.url', '');
        $scheme   = parse_url($appUrl, PHP_URL_SCHEME) ?? 'https';
        $appHost  = parse_url($appUrl, PHP_URL_HOST) ?? '';
        $parts    = explode('.', $appHost);
        // Strip the SaaS subdomain (e.g. "manager.tp.makeit.uno" → "tp.makeit.uno")
        $baseHost = count($parts) > 2 ? implode('.', array_slice($parts, 1)) : $appHost;

        return $scheme . '://' . $tenant['subdomain'] . '.' . $baseHost;
    }

    private function getTenantPdo(): \PDO
    {
        $host = $this->config->get('tenant_db.host', 'localhost');
        $port = (int)$this->config->get('tenant_db.port', 3306);
        $db   = $this->config->get('tenant_db.database', '');
        $user = $this->config->get('tenant_db.username', '');
        $pass = $this->config->get('tenant_db.password', '');

        return new \PDO(
            "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
            $user, $pass,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
    }

    private function validateTenantData(array $data): array
    {
        $errors = [];
        if (empty($data['practice_name'])) $errors[] = 'Praxisname ist erforderlich.';
        if (empty($data['owner_name']))    $errors[] = 'Name des Therapeuten ist erforderlich.';
        if (empty($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Gültige E-Mail-Adresse erforderlich.';
        } elseif ($this->tenantRepo->findByEmail($data['email'])) {
            $errors[] = 'Diese E-Mail-Adresse ist bereits registriert.';
        }
        return $errors;
    }

    private function generatePassword(): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$';
        $pass  = '';
        for ($i = 0; $i < 12; $i++) {
            $pass .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $pass;
    }
}

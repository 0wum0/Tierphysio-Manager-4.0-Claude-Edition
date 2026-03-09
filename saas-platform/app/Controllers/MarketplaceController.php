<?php

declare(strict_types=1);

namespace Saas\Controllers;

use Saas\Core\Controller;
use Saas\Core\View;
use Saas\Core\Session;
use Saas\Core\Config;
use Saas\Repositories\MarketplaceRepository;
use Saas\Repositories\TenantRepository;
use Saas\Services\PaymentService;

class MarketplaceController extends Controller
{
    public function __construct(
        View                        $view,
        Session                     $session,
        private Config              $config,
        private MarketplaceRepository $marketplaceRepo,
        private TenantRepository    $tenantRepo,
        private PaymentService      $paymentService
    ) {
        parent::__construct($view, $session);
    }

    // ── Public Marketplace ─────────────────────────────────────────────────

    public function index(array $params = []): void
    {
        $this->requireAuth();

        $tenantId     = (int)($this->session->get('saas_tenant_id') ?? 0);
        $plugins      = [];
        $needsUpdate  = false;

        try {
            $plugins = $this->marketplaceRepo->allPlugins(true);
            foreach ($plugins as &$plugin) {
                $plugin['purchased'] = ($tenantId > 0)
                    ? $this->marketplaceRepo->tenantHasPlugin($tenantId, (int)$plugin['id'])
                    : false;
                $plugin['screenshots'] = json_decode($plugin['screenshots'] ?? '[]', true) ?: [];
            }
            unset($plugin);
        } catch (\Throwable) {
            $needsUpdate = true;
        }

        $this->render('marketplace/index.twig', [
            'needs_update' => $needsUpdate,
            'page_title'      => 'Plugin-Marktplatz',
            'active_nav'      => 'marketplace',
            'plugins'         => $plugins,
            'stripe_enabled'  => $this->paymentService->stripeEnabled(),
            'paypal_enabled'  => $this->paymentService->paypalEnabled(),
            'stripe_pub_key'  => $this->config->get('payment.stripe_publishable'),
        ]);
    }

    public function show(array $params = []): void
    {
        $this->requireAuth();

        $plugin = $this->marketplaceRepo->findPlugin((int)($params['id'] ?? 0));
        if (!$plugin) {
            $this->notFound();
        }

        $tenantId  = (int)($this->session->get('saas_tenant_id') ?? 0);
        $purchased = ($tenantId > 0)
            ? $this->marketplaceRepo->tenantHasPlugin($tenantId, (int)$plugin['id'])
            : false;

        $plugin['screenshots'] = json_decode($plugin['screenshots'] ?? '[]', true) ?: [];
        $plugin['requirements'] = json_decode($plugin['requirements'] ?? '[]', true) ?: [];

        $this->render('marketplace/show.twig', [
            'page_title'     => $plugin['name'],
            'active_nav'     => 'marketplace',
            'plugin'         => $plugin,
            'purchased'      => $purchased,
            'stripe_enabled' => $this->paymentService->stripeEnabled(),
            'paypal_enabled' => $this->paymentService->paypalEnabled(),
            'stripe_pub_key' => $this->config->get('payment.stripe_publishable'),
        ]);
    }

    // ── Purchase via Stripe ────────────────────────────────────────────────

    public function buyStripe(array $params = []): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $plugin   = $this->marketplaceRepo->findPlugin((int)($params['id'] ?? 0));
        $tenantId = (int)($this->session->get('saas_tenant_id') ?? 0);

        if (!$plugin) { $this->notFound(); }
        if (!$tenantId) {
            $this->session->flash('error', 'Kein Tenant-Konto verknüpft.');
            $this->redirect('/marketplace');
        }

        if ($this->marketplaceRepo->tenantHasPlugin($tenantId, (int)$plugin['id'])) {
            $this->session->flash('info', 'Plugin bereits aktiviert.');
            $this->redirect('/marketplace');
        }

        if ($plugin['price_type'] === 'free' || (float)$plugin['price'] === 0.0) {
            $this->activateFree((int)$plugin['id'], $tenantId);
            $this->redirect('/marketplace');
        }

        try {
            $baseUrl    = rtrim($this->config->get('app.url'), '/');
            $successUrl = $baseUrl . '/marketplace/' . $plugin['id'] . '/stripe/success?session_id={CHECKOUT_SESSION_ID}';
            $cancelUrl  = $baseUrl . '/marketplace/' . $plugin['id'];

            $checkoutUrl = $this->paymentService->createStripeCheckout(
                (float)$plugin['price'],
                'EUR',
                $plugin['name'],
                $successUrl,
                $cancelUrl,
                ['tenant_id' => (string)$tenantId, 'plugin_id' => (string)$plugin['id']]
            );

            header('Location: ' . $checkoutUrl);
            exit;
        } catch (\Throwable $e) {
            $this->session->flash('error', 'Stripe Fehler: ' . $e->getMessage());
            $this->redirect('/marketplace/' . $plugin['id']);
        }
    }

    public function stripeSuccess(array $params = []): void
    {
        $this->requireAuth();

        $sessionId = $_GET['session_id'] ?? '';
        $plugin    = $this->marketplaceRepo->findPlugin((int)($params['id'] ?? 0));
        $tenantId  = (int)$this->session->get('saas_tenant_id');

        if (!$plugin || !$tenantId || !$sessionId) { $this->notFound(); }

        try {
            $stripeSession = $this->paymentService->getStripeSession($sessionId);

            if (($stripeSession['payment_status'] ?? '') === 'paid') {
                if (!$this->marketplaceRepo->tenantHasPlugin($tenantId, (int)$plugin['id'])) {
                    $this->marketplaceRepo->createPurchase([
                        'tenant_id'      => $tenantId,
                        'plugin_id'      => (int)$plugin['id'],
                        'status'         => 'active',
                        'payment_method' => 'stripe',
                        'payment_ref'    => $stripeSession['payment_intent'] ?? $sessionId,
                        'amount_paid'    => (float)$plugin['price'],
                        'expires_at'     => null,
                    ]);
                }
                $this->session->flash('success', '✓ Plugin <strong>' . htmlspecialchars($plugin['name']) . '</strong> erfolgreich aktiviert!');
            } else {
                $this->session->flash('error', 'Zahlung nicht abgeschlossen.');
            }
        } catch (\Throwable $e) {
            $this->session->flash('error', 'Fehler beim Verifizieren: ' . $e->getMessage());
        }

        $this->redirect('/marketplace');
    }

    // ── Purchase via PayPal ────────────────────────────────────────────────

    public function buyPaypal(array $params = []): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $plugin   = $this->marketplaceRepo->findPlugin((int)($params['id'] ?? 0));
        $tenantId = (int)($this->session->get('saas_tenant_id') ?? 0);

        if (!$plugin) { $this->notFound(); }
        if (!$tenantId) {
            $this->session->flash('error', 'Kein Tenant-Konto verknüpft.');
            $this->redirect('/marketplace');
        }

        if ($this->marketplaceRepo->tenantHasPlugin($tenantId, (int)$plugin['id'])) {
            $this->session->flash('info', 'Plugin bereits aktiviert.');
            $this->redirect('/marketplace');
        }

        if ($plugin['price_type'] === 'free' || (float)$plugin['price'] === 0.0) {
            $this->activateFree((int)$plugin['id'], $tenantId);
            $this->redirect('/marketplace');
        }

        try {
            $baseUrl   = rtrim($this->config->get('app.url'), '/');
            $returnUrl = $baseUrl . '/marketplace/' . $plugin['id'] . '/paypal/capture';
            $cancelUrl = $baseUrl . '/marketplace/' . $plugin['id'];

            $order = $this->paymentService->createPayPalOrder(
                (float)$plugin['price'],
                'EUR',
                $plugin['name'],
                $returnUrl,
                $cancelUrl,
                ['tenant_id' => $tenantId, 'plugin_id' => (int)$plugin['id']]
            );

            // Store order info in session for capture step
            $this->session->set('paypal_order_plugin_id', (int)$plugin['id']);
            $this->session->set('paypal_order_amount', (float)$plugin['price']);

            header('Location: ' . $order['approval_url']);
            exit;
        } catch (\Throwable $e) {
            $this->session->flash('error', 'PayPal Fehler: ' . $e->getMessage());
            $this->redirect('/marketplace/' . $plugin['id']);
        }
    }

    public function paypalCapture(array $params = []): void
    {
        $this->requireAuth();

        $orderId  = $_GET['token'] ?? '';
        $plugin   = $this->marketplaceRepo->findPlugin((int)($params['id'] ?? 0));
        $tenantId = (int)$this->session->get('saas_tenant_id');

        if (!$plugin || !$tenantId || !$orderId) { $this->notFound(); }

        try {
            $capture = $this->paymentService->capturePayPalOrder($orderId);

            if (!$this->marketplaceRepo->tenantHasPlugin($tenantId, (int)$plugin['id'])) {
                $amount = $this->session->get('paypal_order_amount', (float)$plugin['price']);
                $this->marketplaceRepo->createPurchase([
                    'tenant_id'      => $tenantId,
                    'plugin_id'      => (int)$plugin['id'],
                    'status'         => 'active',
                    'payment_method' => 'paypal',
                    'payment_ref'    => $orderId,
                    'amount_paid'    => $amount,
                    'expires_at'     => null,
                ]);
            }

            $this->session->flash('success', '✓ Plugin <strong>' . htmlspecialchars($plugin['name']) . '</strong> erfolgreich aktiviert!');
        } catch (\Throwable $e) {
            $this->session->flash('error', 'PayPal Fehler: ' . $e->getMessage());
        }

        $this->redirect('/marketplace');
    }

    // ── Free activation ────────────────────────────────────────────────────

    public function activateManual(array $params = []): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $plugin   = $this->marketplaceRepo->findPlugin((int)($params['id'] ?? 0));
        $tenantId = (int)$this->session->get('saas_tenant_id');

        if (!$plugin || !$tenantId) { $this->notFound(); }

        if ((float)$plugin['price'] > 0 && $plugin['price_type'] !== 'free') {
            $this->session->flash('error', 'Dieses Plugin ist nicht kostenlos.');
            $this->redirect('/marketplace');
        }

        $this->activateFree((int)$plugin['id'], $tenantId);
        $this->redirect('/marketplace');
    }

    private function activateFree(int $pluginId, int $tenantId): void
    {
        if (!$this->marketplaceRepo->tenantHasPlugin($tenantId, $pluginId)) {
            $plugin = $this->marketplaceRepo->findPlugin($pluginId);
            $this->marketplaceRepo->createPurchase([
                'tenant_id'      => $tenantId,
                'plugin_id'      => $pluginId,
                'status'         => 'active',
                'payment_method' => 'manual',
                'payment_ref'    => null,
                'amount_paid'    => 0.00,
                'expires_at'     => null,
            ]);
            $this->session->flash('success', '✓ Plugin <strong>' . htmlspecialchars($plugin['name'] ?? '') . '</strong> kostenlos aktiviert!');
        }
    }
}

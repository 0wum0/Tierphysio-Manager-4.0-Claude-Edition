<?php

declare(strict_types=1);

use Saas\Controllers\AuthController;
use Saas\Controllers\DashboardController;
use Saas\Controllers\TenantController;
use Saas\Controllers\PlansController;
use Saas\Controllers\LegalController;
use Saas\Controllers\LicenseApiController;
use Saas\Controllers\RegistrationController;
use Saas\Controllers\SettingsController;
use Saas\Controllers\MarketplaceController;
use Saas\Controllers\AdminMarketplaceController;
use Saas\Controllers\UpdaterController;

// ── Public Registration ────────────────────────────────────────────────────
$router->get('/register',          [RegistrationController::class, 'index']);
$router->get('/register/{plan}',   [RegistrationController::class, 'form']);
$router->post('/register',         [RegistrationController::class, 'submit']);

// ── Public Legal Documents ─────────────────────────────────────────────────
$router->get('/legal/{slug}',      [LegalController::class, 'view']);

// ── License API (called by Praxissoftware) ─────────────────────────────────
$router->post('/api/license/verify',  [LicenseApiController::class, 'verify']);
$router->get('/api/license/check',    [LicenseApiController::class, 'check']);
$router->post('/api/license/token',   [LicenseApiController::class, 'token']);

// ── Auth ───────────────────────────────────────────────────────────────────
$router->get('/admin/login',  [AuthController::class, 'loginForm']);
$router->post('/admin/login', [AuthController::class, 'login']);
$router->get('/admin/logout', [AuthController::class, 'logout']);

// ── Admin Dashboard ────────────────────────────────────────────────────────
$router->get('/admin',        [DashboardController::class, 'index']);
$router->get('/admin/',       [DashboardController::class, 'index']);

// ── Tenant Management ──────────────────────────────────────────────────────
$router->get('/admin/tenants',                  [TenantController::class, 'index']);
$router->get('/admin/tenants/create',           [TenantController::class, 'createForm']);
$router->post('/admin/tenants/create',          [TenantController::class, 'create']);
$router->get('/admin/tenants/{id}',             [TenantController::class, 'show']);
$router->get('/admin/tenants/{id}/edit',        [TenantController::class, 'editForm']);
$router->post('/admin/tenants/{id}/edit',       [TenantController::class, 'edit']);
$router->post('/admin/tenants/{id}/suspend',    [TenantController::class, 'suspend']);
$router->post('/admin/tenants/{id}/reactivate', [TenantController::class, 'reactivate']);
$router->post('/admin/tenants/{id}/cancel',     [TenantController::class, 'cancel']);
$router->post('/admin/tenants/{id}/license',    [TenantController::class, 'issueLicense']);
$router->post('/admin/tenants/{id}/delete',     [TenantController::class, 'delete']);

// ── Plans Management ───────────────────────────────────────────────────────
$router->get('/admin/plans',            [PlansController::class, 'index']);
$router->get('/admin/plans/{id}/edit',  [PlansController::class, 'edit']);
$router->post('/admin/plans/{id}/edit', [PlansController::class, 'update']);

// ── Legal Documents Management ─────────────────────────────────────────────
$router->get('/admin/legal',            [LegalController::class, 'index']);
$router->get('/admin/legal/{id}/edit',  [LegalController::class, 'edit']);
$router->post('/admin/legal/{id}/edit', [LegalController::class, 'update']);

// ── Marketplace (Tenant) ──────────────────────────────────────────
$router->get('/marketplace',                               [MarketplaceController::class, 'index']);
$router->get('/marketplace/{id}',                          [MarketplaceController::class, 'show']);
$router->post('/marketplace/{id}/activate',                [MarketplaceController::class, 'activateManual']);
$router->post('/marketplace/{id}/buy/stripe',              [MarketplaceController::class, 'buyStripe']);
$router->get('/marketplace/{id}/stripe/success',           [MarketplaceController::class, 'stripeSuccess']);
$router->post('/marketplace/{id}/buy/paypal',              [MarketplaceController::class, 'buyPaypal']);
$router->get('/marketplace/{id}/paypal/capture',           [MarketplaceController::class, 'paypalCapture']);

// ── Admin Marketplace ────────────────────────────────────────────
$router->get('/admin/marketplace',                         [AdminMarketplaceController::class, 'index']);
$router->get('/admin/marketplace/create',                  [AdminMarketplaceController::class, 'createForm']);
$router->post('/admin/marketplace/create',                 [AdminMarketplaceController::class, 'create']);
$router->get('/admin/marketplace/{id}/edit',               [AdminMarketplaceController::class, 'editForm']);
$router->post('/admin/marketplace/{id}/edit',              [AdminMarketplaceController::class, 'update']);
$router->post('/admin/marketplace/{id}/delete',            [AdminMarketplaceController::class, 'delete']);
$router->post('/admin/marketplace/{id}/grant',             [AdminMarketplaceController::class, 'grantManual']);
$router->post('/admin/marketplace/{id}/revoke',            [AdminMarketplaceController::class, 'revokeManual']);
$router->post('/admin/marketplace/{id}/toggle',            [AdminMarketplaceController::class, 'toggle']);
$router->get('/admin/tenants/{id}/plugins',                [AdminMarketplaceController::class, 'tenantPlugins']);

// ── Updater ──────────────────────────────────────────────────────────────
$router->get('/admin/updater',     [UpdaterController::class, 'index']);
$router->post('/admin/updater/run',[UpdaterController::class, 'run']);

// ── Settings ──────────────────────────────────────────────────────────────
$router->get('/admin/settings',  [SettingsController::class, 'index']);
$router->post('/admin/settings', [SettingsController::class, 'save']);

// ── Root redirect ──────────────────────────────────────────────────────────
$router->get('/', function (array $params): void {
    header('Location: /admin');
    exit;
});

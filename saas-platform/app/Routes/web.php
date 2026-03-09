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

// ── Settings ──────────────────────────────────────────────────────────────
$router->get('/admin/settings',  [SettingsController::class, 'index']);
$router->post('/admin/settings', [SettingsController::class, 'save']);

// ── Root redirect ──────────────────────────────────────────────────────────
$router->get('/', function (array $params): void {
    header('Location: /admin');
    exit;
});

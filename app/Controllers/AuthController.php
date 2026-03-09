<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Config;
use App\Core\Session;
use App\Core\Translator;
use App\Core\View;
use App\Repositories\UserRepository;
use App\Repositories\SettingsRepository;

class AuthController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        Config $config,
        Translator $translator,
        private readonly UserRepository $userRepository,
        private readonly SettingsRepository $settingsRepository
    ) {
        parent::__construct($view, $session, $config, $translator);
    }

    public function showLogin(array $params = []): void
    {
        $settings = $this->settingsRepository->all();
        $this->render('auth/login.twig', [
            'page_title'   => $this->translator->trans('auth.login_title'),
            'company_name' => $settings['company_name'] ?? '',
            'company_logo' => $settings['company_logo'] ?? '',
        ]);
    }

    public function login(array $params = []): void
    {
        $this->validateCsrf();

        $email    = trim($this->post('email', ''));
        $password = $this->post('password', '');

        if (empty($email) || empty($password)) {
            $this->session->flash('error', $this->translator->trans('auth.fill_all_fields'));
            $this->redirect('/login');
            return;
        }

        $user = $this->userRepository->findByEmail($email);

        if (!$user || !password_verify($password, $user['password'])) {
            $this->session->flash('error', $this->translator->trans('auth.invalid_credentials'));
            $this->redirect('/login');
            return;
        }

        if ((int)$user['active'] !== 1) {
            $this->session->flash('error', $this->translator->trans('auth.account_inactive'));
            $this->redirect('/login');
            return;
        }

        $this->session->setUser($user);
        $this->session->set('user_last_login', $user['last_login'] ?? null);
        $this->userRepository->updateLastLogin($user['id']);
        $this->session->flash('success', $this->translator->trans('auth.welcome', ['name' => $user['name']]));
        $this->redirect('/dashboard');
    }

    public function logout(array $params = []): void
    {
        $this->validateCsrf();
        $this->session->destroy();
        $this->redirect('/login');
    }

    /**
     * SaaS admin impersonation endpoint.
     * Called via: /admin-login?token=<hex>
     * The token was written into `_impersonate_token` in this tenant's settings table
     * by the SaaS platform. It is valid for 5 minutes, single-use.
     */
    public function adminLogin(array $params = []): void
    {
        $token = trim($_GET['token'] ?? '');

        if ($token === '') {
            $this->redirect('/login');
            return;
        }

        $stored = $this->settingsRepository->get('_impersonate_token');

        if (!$stored) {
            $this->session->flash('error', 'Ungültiger oder abgelaufener Login-Link.');
            $this->redirect('/login');
            return;
        }

        [$storedToken, $expiresAt] = explode('|', $stored, 2) + ['', ''];

        if (!hash_equals($storedToken, $token)) {
            $this->session->flash('error', 'Ungültiger Login-Token.');
            $this->redirect('/login');
            return;
        }

        if (strtotime($expiresAt) < time()) {
            $this->session->flash('error', 'Login-Link abgelaufen (gültig 5 Minuten).');
            $this->redirect('/login');
            return;
        }

        // Invalidate token immediately (single-use)
        $this->settingsRepository->set('_impersonate_token', '');

        // Find the first admin user in this tenant
        $admin = $this->userRepository->findFirstAdmin();

        if (!$admin) {
            $this->session->flash('error', 'Kein Admin-Benutzer gefunden.');
            $this->redirect('/login');
            return;
        }

        $this->session->setUser($admin);
        $this->session->set('impersonated_by_saas', true);
        $this->userRepository->updateLastLogin($admin['id']);
        $this->session->flash('success', 'Als SaaS-Admin eingeloggt: ' . $admin['name']);
        $this->redirect('/dashboard');
    }
}

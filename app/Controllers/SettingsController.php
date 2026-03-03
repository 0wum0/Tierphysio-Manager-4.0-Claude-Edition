<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Config;
use App\Core\Session;
use App\Core\Translator;
use App\Core\View;
use App\Core\PluginManager;
use App\Services\SettingsService;
use App\Services\MigrationService;
use App\Repositories\UserRepository;

class SettingsController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        Config $config,
        Translator $translator,
        private readonly SettingsService $settingsService,
        private readonly PluginManager $pluginManager,
        private readonly MigrationService $migrationService,
        private readonly UserRepository $userRepository
    ) {
        parent::__construct($view, $session, $config, $translator);
    }

    public function index(array $params = []): void
    {
        $settings = $this->settingsService->all();

        $this->render('settings/index.twig', [
            'page_title' => $this->translator->trans('nav.settings'),
            'settings'   => $settings,
        ]);
    }

    public function update(array $params = []): void
    {
        $this->validateCsrf();

        $allowed = [
            'company_name', 'company_street', 'company_zip', 'company_city',
            'company_phone', 'company_email', 'company_website',
            'bank_name', 'bank_iban', 'bank_bic',
            'payment_terms', 'invoice_prefix', 'invoice_start_number',
            'tax_number', 'vat_number', 'default_tax_rate',
            'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password',
            'smtp_encryption', 'mail_from_name', 'mail_from_address',
            'default_language', 'default_theme',
        ];

        $data = [];
        foreach ($allowed as $key) {
            if (isset($_POST[$key])) {
                $data[$key] = $this->sanitize($_POST[$key]);
            }
        }

        foreach ($data as $key => $value) {
            $this->settingsService->set($key, $value);
        }

        $this->session->flash('success', $this->translator->trans('settings.saved'));
        $this->redirect('/einstellungen');
    }

    public function uploadLogo(array $params = []): void
    {
        $this->validateCsrf();

        $destination = STORAGE_PATH . '/uploads';
        $filename    = $this->uploadFile('logo', $destination, ['image/jpeg', 'image/png', 'image/svg+xml', 'image/gif']);

        if ($filename === false) {
            $this->session->flash('error', $this->translator->trans('settings.logo_upload_failed'));
            $this->redirect('/einstellungen');
            return;
        }

        $this->settingsService->set('company_logo', $filename);
        $this->session->flash('success', $this->translator->trans('settings.logo_updated'));
        $this->redirect('/einstellungen');
    }

    public function plugins(array $params = []): void
    {
        $plugins = $this->pluginManager->getAllAvailablePlugins();

        $this->render('settings/plugins.twig', [
            'page_title' => $this->translator->trans('settings.plugins'),
            'plugins'    => $plugins,
        ]);
    }

    public function enablePlugin(array $params = []): void
    {
        $this->validateCsrf();
        $this->pluginManager->enablePlugin($params['name']);
        $this->session->flash('success', $this->translator->trans('settings.plugin_enabled'));
        $this->redirect('/einstellungen/plugins');
    }

    public function disablePlugin(array $params = []): void
    {
        $this->validateCsrf();
        $this->pluginManager->disablePlugin($params['name']);
        $this->session->flash('success', $this->translator->trans('settings.plugin_disabled'));
        $this->redirect('/einstellungen/plugins');
    }

    public function updater(array $params = []): void
    {
        $currentVersion  = $this->migrationService->getCurrentVersion();
        $latestVersion   = $this->migrationService->getLatestVersion();
        $pendingMigrations = $this->migrationService->getPendingMigrations();

        $this->render('settings/updater.twig', [
            'page_title'      => $this->translator->trans('settings.updater'),
            'current_version' => $currentVersion,
            'latest_version'  => $latestVersion,
            'pending'         => $pendingMigrations,
            'up_to_date'      => empty($pendingMigrations),
            'php_version'     => PHP_VERSION,
            'app_env'         => $this->config->get('app.env', 'production'),
        ]);
    }

    public function runMigrations(array $params = []): void
    {
        $this->validateCsrf();

        try {
            $ran = $this->migrationService->runPending();
            $this->session->flash('success', $this->translator->trans('settings.migrations_ran', ['count' => count($ran)]));
        } catch (\Throwable $e) {
            $this->session->flash('error', $this->translator->trans('settings.migrations_failed') . ': ' . $e->getMessage());
        }

        $this->redirect('/einstellungen/updater');
    }

    public function users(array $params = []): void
    {
        $users = $this->userRepository->findAll();

        $this->render('settings/users.twig', [
            'page_title' => $this->translator->trans('settings.users'),
            'users'      => $users,
        ]);
    }

    public function createUser(array $params = []): void
    {
        $this->validateCsrf();

        $name     = $this->sanitize($this->post('name', ''));
        $email    = $this->sanitize($this->post('email', ''));
        $password = $this->post('password', '');
        $role     = $this->sanitize($this->post('role', 'mitarbeiter'));

        if (empty($name) || empty($email) || empty($password)) {
            $this->session->flash('error', $this->translator->trans('settings.fill_required'));
            $this->redirect('/einstellungen/benutzer');
            return;
        }

        if ($this->userRepository->findByEmail($email)) {
            $this->session->flash('error', $this->translator->trans('settings.email_exists'));
            $this->redirect('/einstellungen/benutzer');
            return;
        }

        $this->userRepository->create([
            'name'     => $name,
            'email'    => $email,
            'password' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
            'role'     => $role,
            'active'   => 1,
        ]);

        $this->session->flash('success', $this->translator->trans('settings.user_created'));
        $this->redirect('/einstellungen/benutzer');
    }

    public function deleteUser(array $params = []): void
    {
        $this->validateCsrf();

        $currentUserId = (int)$this->session->get('user_id');
        if ((int)$params['id'] === $currentUserId) {
            $this->session->flash('error', $this->translator->trans('settings.cannot_delete_self'));
            $this->redirect('/einstellungen/benutzer');
            return;
        }

        $this->userRepository->delete((int)$params['id']);
        $this->session->flash('success', $this->translator->trans('settings.user_deleted'));
        $this->redirect('/einstellungen/benutzer');
    }
}

<?php

declare(strict_types=1);

namespace Saas\Controllers;

use Saas\Core\Controller;
use Saas\Core\View;
use Saas\Core\Session;
use Saas\Core\Config;

class SettingsController extends Controller
{
    private string $envPath;

    public function __construct(
        View    $view,
        Session $session,
        private Config $config
    ) {
        parent::__construct($view, $session);
        $this->envPath = $this->config->getRootPath() . '/.env';
    }

    public function index(array $params = []): void
    {
        $this->requireAuth();

        $env = $this->readEnv();

        $this->render('admin/settings/index.twig', [
            'page_title' => 'Einstellungen',
            'active_nav' => 'settings',
            'env'        => $env,
        ]);
    }

    public function save(array $params = []): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $fields = [
            // App
            'APP_NAME', 'APP_URL', 'APP_DEBUG', 'APP_ENV',
            // SaaS DB
            'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
            // Tenant DB
            'TENANT_DB_HOST', 'TENANT_DB_PORT', 'TENANT_DB_DATABASE',
            'TENANT_DB_USERNAME', 'TENANT_DB_PASSWORD', 'TENANT_TABLE_PREFIX',
            // Mail
            'MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD',
            'MAIL_ENCRYPTION', 'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME',
            // License
            'LICENSE_SECRET',
            // Session
            'SESSION_SECURE',
        ];

        $current = $this->readEnv();

        foreach ($fields as $key) {
            $value = $_POST[$key] ?? null;
            if ($value === null) continue;

            // Checkboxes: only present when checked
            if (in_array($key, ['APP_DEBUG', 'SESSION_SECURE'], true)) {
                $value = isset($_POST[$key]) ? 'true' : 'false';
            }

            $current[$key] = trim($value);
        }

        $this->writeEnv($current);

        $this->session->flash('success', 'Einstellungen gespeichert. Änderungen an DB/Mail werden nach dem nächsten Request aktiv.');
        $this->redirect('/admin/settings');
    }

    private function readEnv(): array
    {
        $env = [];
        if (!file_exists($this->envPath)) {
            return $env;
        }

        foreach (file($this->envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;

            if (str_contains($line, '=')) {
                [$key, $val] = explode('=', $line, 2);
                $env[trim($key)] = trim($val, " \t\"'");
            }
        }

        return $env;
    }

    private function writeEnv(array $data): void
    {
        if (!file_exists($this->envPath)) {
            // Bootstrap from example if .env doesn't exist yet
            $example = $this->config->getRootPath() . '/.env.example';
            if (file_exists($example)) {
                copy($example, $this->envPath);
            }
        }

        // Read existing file to preserve comments and ordering
        $lines = file_exists($this->envPath)
            ? file($this->envPath, FILE_IGNORE_NEW_LINES)
            : [];

        $written = [];
        $output  = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Preserve comments and blank lines
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                $output[] = $line;
                continue;
            }

            if (str_contains($trimmed, '=')) {
                [$key] = explode('=', $trimmed, 2);
                $key   = trim($key);

                if (array_key_exists($key, $data)) {
                    $val      = $data[$key];
                    $needsQuote = str_contains($val, ' ');
                    $output[] = $key . '=' . ($needsQuote ? '"' . $val . '"' : $val);
                    $written[$key] = true;
                } else {
                    $output[] = $line;
                }
            } else {
                $output[] = $line;
            }
        }

        // Append any keys not yet in the file
        foreach ($data as $key => $val) {
            if (!isset($written[$key])) {
                $needsQuote = str_contains((string)$val, ' ');
                $output[] = $key . '=' . ($needsQuote ? '"' . $val . '"' : $val);
            }
        }

        file_put_contents($this->envPath, implode("\n", $output) . "\n");
    }
}

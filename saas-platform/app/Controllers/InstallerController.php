<?php

declare(strict_types=1);

namespace Saas\Controllers;

use PDO;
use Saas\Core\Controller;
use Saas\Core\View;
use Saas\Core\Session;
use Saas\Core\Config;

class InstallerController extends Controller
{
    public function __construct(
        View            $view,
        Session         $session,
        private Config  $config
    ) {
        parent::__construct($view, $session);
    }

    public function index(array $params = []): void
    {
        $this->render('installer/index.twig', [
            'page_title' => 'Tierphysio SaaS – Installation',
            'checks'     => $this->runChecks(),
        ]);
    }

    public function run(array $params = []): void
    {
        $this->verifyCsrf();

        $dbHost   = trim($this->post('db_host', 'localhost'));
        $dbPort   = (int)$this->post('db_port', 3306);
        $dbName   = trim($this->post('db_name', 'tierphysio_saas'));
        $dbUser   = trim($this->post('db_user', ''));
        $dbPass   = $this->post('db_pass', '');
        $appUrl   = rtrim(trim($this->post('app_url', '')), '/');
        $adminName  = trim($this->post('admin_name', ''));
        $adminEmail = strtolower(trim($this->post('admin_email', '')));
        $adminPass  = $this->post('admin_password', '');
        $licSecret  = trim($this->post('license_secret', bin2hex(random_bytes(32))));

        $errors = [];
        if (!$dbUser)       $errors[] = 'Datenbank-Benutzer ist erforderlich.';
        if (!$adminName)    $errors[] = 'Admin-Name ist erforderlich.';
        if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) $errors[] = 'Gültige Admin-E-Mail erforderlich.';
        if (strlen($adminPass) < 8) $errors[] = 'Admin-Passwort muss mindestens 8 Zeichen haben.';
        if (strlen($licSecret) < 32) $errors[] = 'Lizenz-Geheimnis muss mindestens 32 Zeichen haben.';

        if ($errors) {
            $this->render('installer/index.twig', [
                'page_title' => 'Tierphysio SaaS – Installation',
                'checks'     => $this->runChecks(),
                'errors'     => $errors,
                'old'        => $_POST,
            ]);
            return;
        }

        // Test DB connection — database must already exist on shared hosting
        try {
            $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (\Throwable $e) {
            $this->render('installer/index.twig', [
                'page_title' => 'Tierphysio SaaS – Installation',
                'checks'     => $this->runChecks(),
                'errors'     => ['Datenbankverbindung fehlgeschlagen: ' . $e->getMessage()],
                'old'        => $_POST,
            ]);
            return;
        }

        // Run migrations
        try {
            $migrationPath = $this->config->getRootPath() . '/migrations/001_initial_schema.sql';
            if (file_exists($migrationPath)) {
                $sql = file_get_contents($migrationPath);
                foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                    if ($stmt !== '') {
                        try { $pdo->exec($stmt); } catch (\Throwable) {}
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->render('installer/index.twig', [
                'page_title' => 'Tierphysio SaaS – Installation',
                'checks'     => $this->runChecks(),
                'errors'     => ['Migration fehlgeschlagen: ' . $e->getMessage()],
                'old'        => $_POST,
            ]);
            return;
        }

        // Create first admin
        $hash = password_hash($adminPass, PASSWORD_BCRYPT, ['cost' => 12]);
        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO saas_admins (name, email, password, role) VALUES (?, ?, ?, 'superadmin')"
        );
        $stmt->execute([$adminName, $adminEmail, $hash]);

        // Write .env
        $appKey   = bin2hex(random_bytes(32));
        $envContent = <<<ENV
APP_NAME="Tierphysio SaaS"
APP_ENV=production
APP_DEBUG=false
APP_URL={$appUrl}
APP_KEY={$appKey}

DB_HOST={$dbHost}
DB_PORT={$dbPort}
DB_DATABASE={$dbName}
DB_USERNAME={$dbUser}
DB_PASSWORD={$dbPass}

TENANT_DB_HOST={$dbHost}
TENANT_DB_PORT={$dbPort}
TENANT_DB_DATABASE={$dbName}
TENANT_DB_USERNAME={$dbUser}
TENANT_DB_PASSWORD={$dbPass}
TENANT_TABLE_PREFIX=tpm

PRACTICE_SOFTWARE_PATH=
PRACTICE_SOFTWARE_URL=

SESSION_LIFETIME=120
SESSION_SECURE=true

MAIL_DRIVER=smtp
MAIL_HOST=localhost
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@tierphysio.de
MAIL_FROM_NAME="Tierphysio SaaS"

LICENSE_SECRET={$licSecret}

APP_LOCALE=de
INSTALLED=true
ENV;

        file_put_contents($this->config->getRootPath() . '/.env', $envContent);

        $this->render('installer/success.twig', [
            'page_title'  => 'Installation abgeschlossen',
            'admin_email' => $adminEmail,
            'app_url'     => $appUrl,
        ]);
    }

    private function runChecks(): array
    {
        return [
            ['name' => 'PHP >= 8.3',        'ok' => version_compare(PHP_VERSION, '8.3.0', '>=')],
            ['name' => 'PDO MySQL',          'ok' => extension_loaded('pdo_mysql')],
            ['name' => 'OpenSSL',            'ok' => extension_loaded('openssl')],
            ['name' => 'JSON',               'ok' => extension_loaded('json')],
            ['name' => 'mbstring',           'ok' => extension_loaded('mbstring')],
            ['name' => 'storage/ schreibbar','ok' => is_writable($this->config->getRootPath() . '/storage')],
            ['name' => '.env schreibbar',    'ok' => is_writable($this->config->getRootPath())],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Config;
use App\Core\Session;
use App\Core\Translator;
use App\Core\View;
use App\Core\Database;
use App\Services\MigrationService;
use PDO;

class InstallerController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        Config $config,
        Translator $translator
    ) {
        parent::__construct($view, $session, $config, $translator);
    }

    public function index(array $params = []): void
    {
        $step = (int)($params['step'] ?? 1);
        $step = max(1, min(4, $step));

        $detectedUrl = $this->detectAppUrl();

        $data = [
            'page_title'   => 'Tierphysio Manager 3.0 - Installation',
            'step'         => $step,
            'detected_url' => $detectedUrl,
        ];

        if ($step === 1) {
            $data['requirements'] = $this->checkRequirements();
        }

        $this->render('installer/index.twig', $data);
    }

    private function detectAppUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host;
    }

    public function checkDb(array $params = []): void
    {
        $host     = trim($this->post('db_host', 'localhost'));
        $port     = (int)$this->post('db_port', 3306);
        $database = trim($this->post('db_name', ''));
        $username = trim($this->post('db_user', ''));
        $password = $this->post('db_password', '');

        try {
            Database::createFromCredentials($host, $port, $database, $username, $password);
            $this->json(['success' => true, 'message' => 'Verbindung erfolgreich!']);
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function runStep2(array $params = []): void
    {
        $this->validateCsrf();

        $host     = trim($this->post('db_host', 'localhost'));
        $port     = (int)$this->post('db_port', 3306);
        $database = trim($this->post('db_name', ''));
        $username = trim($this->post('db_user', ''));
        $password = $this->post('db_password', '');

        if (empty($database) || empty($username)) {
            $this->session->flash('error', 'Bitte Datenbankname und Benutzer angeben.');
            $this->redirect('/install/schritt/2');
            return;
        }

        try {
            $pdo = Database::createFromCredentials($host, $port, $database, $username, $password);
            $this->executeMigrations($pdo);
            // Store DB credentials in session for step 3
            $this->session->set('install_db', compact('host', 'port', 'database', 'username', 'password'));
            $this->redirect('/install/schritt/3');
        } catch (\Throwable $e) {
            $this->session->flash('error', 'Datenbankfehler: ' . $e->getMessage());
            $this->redirect('/install/schritt/2');
        }
    }

    public function runStep3(array $params = []): void
    {
        $this->validateCsrf();

        $adminName     = $this->sanitize($this->post('admin_name', 'Administrator'));
        $adminEmail    = filter_var($this->post('admin_email', ''), FILTER_SANITIZE_EMAIL);
        $adminPassword = $this->post('admin_password', '');
        $adminConfirm  = $this->post('admin_password_confirm', '');
        $appUrl        = rtrim($this->sanitize($this->post('app_url', 'http://localhost')), '/');
        $appLocale     = in_array($this->post('app_locale'), ['de', 'en'], true) ? $this->post('app_locale') : 'de';

        if (empty($adminEmail) || empty($adminPassword)) {
            $this->session->flash('error', 'Bitte alle Felder ausfüllen.');
            $this->redirect('/install/schritt/3');
            return;
        }

        if (strlen($adminPassword) < 8 || $adminPassword !== $adminConfirm) {
            $this->session->flash('error', 'Passwörter stimmen nicht überein oder zu kurz (min. 8 Zeichen).');
            $this->redirect('/install/schritt/3');
            return;
        }

        $db = $this->session->get('install_db');
        if (!$db) {
            $this->redirect('/install/schritt/2');
            return;
        }

        try {
            $pdo = Database::createFromCredentials($db['host'], $db['port'], $db['database'], $db['username'], $db['password']);
            $this->createAdminUser($pdo, $adminName, $adminEmail, $adminPassword);
            $this->writeEnvFile($db['host'], $db['port'], $db['database'], $db['username'], $db['password'], $appUrl, $appLocale);
            $this->session->delete('install_db');
            $this->redirect('/install/fertig');
        } catch (\Throwable $e) {
            $this->session->flash('error', 'Fehler beim Erstellen des Kontos: ' . $e->getMessage());
            $this->redirect('/install/schritt/3');
        }
    }

    public function fertig(array $params = []): void
    {
        $this->render('installer/index.twig', [
            'page_title'   => 'Installation abgeschlossen — Tierphysio Manager 3.0',
            'step'         => 4,
            'detected_url' => $this->detectAppUrl(),
        ]);
    }

    public function finalizeInstall(array $params = []): void
    {
        // Mark as installed — after this the app switches to web routes
        $envPath = ROOT_PATH . '/.env';
        if (file_exists($envPath)) {
            $env = file_get_contents($envPath);
            $env = str_replace('INSTALLED=false', 'INSTALLED=true', $env);
            file_put_contents($envPath, $env);
        }
        $this->redirect('/login');
    }

    private function checkRequirements(): array
    {
        $storageOk = is_writable(ROOT_PATH . '/storage')
            || (!is_dir(ROOT_PATH . '/storage') && mkdir(ROOT_PATH . '/storage', 0755, true));

        return [
            ['label' => 'PHP >= 8.3',                'ok' => version_compare(PHP_VERSION, '8.3.0', '>='),
             'fix'   => 'PHP 8.3 oder höher wird benötigt. Bitte den Hoster kontaktieren.'],
            ['label' => 'PDO MySQL Extension',       'ok' => extension_loaded('pdo_mysql'),
             'fix'   => 'Die PHP-Erweiterung pdo_mysql ist nicht aktiv. Bitte den Hoster kontaktieren.'],
            ['label' => 'GD / Imagick Extension',    'ok' => extension_loaded('gd') || extension_loaded('imagick'),
             'fix'   => 'Die GD oder Imagick PHP-Erweiterung wird für Bildverarbeitung benötigt.'],
            ['label' => 'mbstring Extension',        'ok' => extension_loaded('mbstring'),
             'fix'   => 'Die mbstring PHP-Erweiterung fehlt.'],
            ['label' => 'fileinfo Extension',        'ok' => extension_loaded('fileinfo'),
             'fix'   => 'Die fileinfo PHP-Erweiterung fehlt.'],
            ['label' => 'openssl Extension',         'ok' => extension_loaded('openssl'),
             'fix'   => 'Die openssl PHP-Erweiterung fehlt.'],
            ['label' => 'vendor/ Ordner vorhanden',  'ok' => is_dir(ROOT_PATH . '/vendor'),
             'fix'   => 'Der vendor/-Ordner fehlt. Bitte die vollständige ZIP-Datei entpacken und alle Dateien inklusive vendor/ hochladen.'],
            ['label' => 'Konfiguration schreibbar',  'ok' => is_writable(ROOT_PATH),
             'fix'   => 'Das Hauptverzeichnis ist nicht schreibbar. Bitte die Berechtigungen auf 755 setzen.'],
            ['label' => 'storage/ schreibbar',       'ok' => $storageOk,
             'fix'   => 'Der storage/-Ordner ist nicht schreibbar. Bitte chmod 755 auf storage/ setzen.'],
        ];
    }

    private function executeMigrations(PDO $pdo): void
    {
        $migrationsPath = ROOT_PATH . '/migrations';
        if (!is_dir($migrationsPath)) return;

        $files = glob($migrationsPath . '/*.sql');
        sort($files);

        foreach ($files as $file) {
            $sql = file_get_contents($file);
            $statements = array_filter(array_map('trim', explode(';', $sql)));
            foreach ($statements as $statement) {
                if (!empty($statement)) {
                    $pdo->exec($statement);
                }
            }
        }
    }

    private function createAdminUser(PDO $pdo, string $name, string $email, string $password): void
    {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, active, created_at) VALUES (?, ?, ?, 'admin', 1, NOW()) ON DUPLICATE KEY UPDATE name = VALUES(name)");
        $stmt->execute([$name, $email, $hash]);
    }

    private function writeEnvFile(string $host, int $port, string $database, string $username, string $password, string $appUrl = 'http://localhost', string $locale = 'de'): void
    {
        $key = bin2hex(random_bytes(32));

        $env = <<<ENV
APP_NAME="Tierphysio Manager"
APP_VERSION="3.0.0"
APP_ENV=production
APP_DEBUG=false
APP_URL={$appUrl}
APP_KEY={$key}

DB_HOST={$host}
DB_PORT={$port}
DB_DATABASE={$database}
DB_USERNAME={$username}
DB_PASSWORD={$password}

SESSION_LIFETIME=120
SESSION_SECURE=false

MAIL_DRIVER=smtp
MAIL_HOST=localhost
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@{$host}
MAIL_FROM_NAME="Tierphysio Manager"

UPLOAD_MAX_SIZE=10485760

APP_LOCALE={$locale}
INSTALLED=false
DB_VERSION=0
ENV;

        file_put_contents(ROOT_PATH . '/.env', $env);
    }
}

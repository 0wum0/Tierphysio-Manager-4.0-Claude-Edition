<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Config;
use App\Core\Database;
use App\Core\Router;
use App\Core\Session;
use App\Core\View;
use App\Core\PluginManager;
use App\Core\Translator;
use App\Core\TenantResolver;
use Dotenv\Dotenv;

class Application
{
    private static Application $instance;
    private Container $container;
    private Router $router;
    private string $rootPath;

    public function __construct(string $rootPath)
    {
        $this->rootPath = $rootPath;
        self::$instance = $this;

        $this->loadEnvironment();
        $this->container = new Container();
        $this->bootstrap();
    }

    public static function getInstance(): self
    {
        return self::$instance;
    }

    private function loadEnvironment(): void
    {
        $envFile = $this->rootPath . '/.env';
        if (file_exists($envFile)) {
            $dotenv = Dotenv::createImmutable($this->rootPath);
            $dotenv->load();
        }
    }

    private function bootstrap(): void
    {
        $config = new Config($this->rootPath);
        $this->container->singleton(Config::class, fn() => $config);

        $session = new Session($config);
        $session->start();
        $this->container->singleton(Session::class, fn() => $session);

        $translator = new Translator($config->get('app.locale', 'de'), $this->rootPath . '/lang');
        $this->container->singleton(Translator::class, fn() => $translator);

        $view = new View($this->rootPath . '/templates', $config, $session, $translator);
        $this->container->singleton(View::class, fn() => $view);

        if ($config->get('app.installed', false)) {
            $db = new Database($config);
            // Resolve tenant from URL path segment (sets table prefix on $db)
            $tenantResolver = new TenantResolver($config, $db);
            $tenantResolver->resolve();
            $this->container->singleton(Database::class, fn() => $db);
            $this->container->instance(TenantResolver::class, $tenantResolver);

            // Expose tenant base path to all Twig templates as {{ base_path }}
            $slug = $tenantResolver->getSlug();
            $view->addGlobal('base_path', $slug !== '' ? '/' . $slug : '');
            $view->addGlobal('tenant_slug', $slug);
        } else {
            $view->addGlobal('base_path', '');
            $view->addGlobal('tenant_slug', '');
        }

        $pluginManager = new PluginManager($this->rootPath . '/plugins', $this->container);
        $this->container->singleton(PluginManager::class, fn() => $pluginManager);

        if ($config->get('app.installed', false)) {
            $pluginManager->loadPlugins();

            // Override app_name with company_name from DB settings
            try {
                $settingsRepo = new \App\Repositories\SettingsRepository($this->container->get(Database::class));
                $companyName  = $settingsRepo->get('company_name', '');
                if ($companyName !== '') {
                    $view->addGlobal('app_name', $companyName);
                }
                // Also expose settings globally for layout templates
                $view->addGlobal('global_settings', $settingsRepo->all());
            } catch (\Throwable) {}

            // Load per-user UI layout settings (theme, fixed header, etc.)
            try {
                $prefsRepo    = new \App\Repositories\UserPreferencesRepository($this->container->get(Database::class));
                $userId       = (int)($session->get('user_id') ?? 0);
                $uiRaw        = $userId ? $prefsRepo->get($userId, 'ui_layout_settings') : null;
                $view->addGlobal('server_ui_settings', $uiRaw ?? 'null');
            } catch (\Throwable) {
                $view->addGlobal('server_ui_settings', 'null');
            }
        }

        $this->router = new Router($this->container);
        $this->registerRoutes();
    }

    private function registerRoutes(): void
    {
        $config = $this->container->get(Config::class);

        if (!$config->get('app.installed', false)) {
            $this->router->loadRoutes($this->rootPath . '/app/Routes/installer.php');
            return;
        }

        $this->router->loadRoutes($this->rootPath . '/app/Routes/web.php');

        $pluginManager = $this->container->get(PluginManager::class);
        $pluginManager->registerRoutes($this->router);
    }

    public function run(): void
    {
        try {
            $this->router->dispatch();
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }

    public function getContainer(): Container
    {
        return $this->container;
    }

    public function getRootPath(): string
    {
        return $this->rootPath;
    }

    private function handleException(\Throwable $e): void
    {
        $config = $this->container->get(Config::class);
        $debug = $config->get('app.debug', false);

        /* Always log to file */
        $logDir  = $this->rootPath . '/storage/logs';
        $logFile = $logDir . '/error.log';
        if (!is_dir($logDir)) { @mkdir($logDir, 0755, true); }
        @file_put_contents(
            $logFile,
            '[' . date('Y-m-d H:i:s') . '] ' . get_class($e) . ': ' . $e->getMessage()
            . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n"
            . $e->getTraceAsString() . "\n\n",
            FILE_APPEND
        );

        http_response_code(500);

        /* For AJAX/API requests always return JSON so devtools shows the real error */
        $isAjax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
               || (($_SERVER['HTTP_ACCEPT'] ?? '') && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'));
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => $e->getMessage(), 'file' => basename($e->getFile()), 'line' => $e->getLine()]);
            exit;
        }

        if ($debug) {
            echo '<pre style="background:#1a1a2e;color:#e94560;padding:20px;font-family:monospace;">';
            echo '<strong>Exception:</strong> ' . htmlspecialchars($e->getMessage()) . "\n\n";
            echo '<strong>File:</strong> ' . htmlspecialchars($e->getFile()) . ':' . $e->getLine() . "\n\n";
            echo '<strong>Trace:</strong>' . "\n" . htmlspecialchars($e->getTraceAsString());
            echo '</pre>';
        } else {
            echo '<!DOCTYPE html><html><body style="background:#0f0f1a;color:#fff;font-family:sans-serif;text-align:center;padding:100px;">';
            echo '<h1>500 - Interner Serverfehler</h1><p>Bitte versuchen Sie es später erneut.</p>';
            echo '</body></html>';
        }
    }
}

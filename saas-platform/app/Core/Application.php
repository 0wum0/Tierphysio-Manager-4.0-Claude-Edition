<?php

declare(strict_types=1);

namespace Saas\Core;

use Dotenv\Dotenv;

class Application
{
    private static Application $instance;
    private Container $container;
    private Router    $router;

    public function __construct(private string $rootPath)
    {
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

        $view = new View($this->rootPath . '/templates', $config, $session);
        $this->container->singleton(View::class, fn() => $view);

        // Always register Database if credentials are present
        if ($config->get('db.database')) {
            try {
                $db = new Database($config);
                $this->container->singleton(Database::class, fn() => $db);
            } catch (\Throwable) {
                // DB unavailable — installer will handle this
            }
        }

        if ($config->get('app.installed', false)) {
            // Expose logged-in user to view
            $view->addGlobal('auth_user', $session->get('saas_user'));
            $view->addGlobal('auth_role', $session->get('saas_role'));
        }

        $this->router = new Router($this->container);
        $this->registerRoutes($config);
    }

    private function registerRoutes(Config $config): void
    {
        // Load web routes if DB is configured, installer routes otherwise
        if ($config->get('db.database') && $config->get('db.username')) {
            $this->router->loadRoutes($this->rootPath . '/app/Routes/web.php');
        } else {
            $this->router->loadRoutes($this->rootPath . '/app/Routes/installer.php');
        }
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
        $debug  = $config->get('app.debug', false);

        $logDir  = $this->rootPath . '/storage/logs';
        $logFile = $logDir . '/error.log';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        @file_put_contents(
            $logFile,
            '[' . date('Y-m-d H:i:s') . '] ' . get_class($e) . ': ' . $e->getMessage()
            . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n"
            . $e->getTraceAsString() . "\n\n",
            FILE_APPEND
        );

        // Temporary debug: use 200 so browser renders the error output
        http_response_code(200);

        echo '<pre style="background:#1a1a2e;color:#e94560;padding:20px;font-family:monospace;white-space:pre-wrap;">';
        echo '<strong>' . get_class($e) . '</strong>: ' . htmlspecialchars($e->getMessage()) . "\n\n";
        echo '<strong>File:</strong> ' . htmlspecialchars($e->getFile()) . ':' . $e->getLine() . "\n\n";
        echo '<strong>Trace:</strong>' . "\n" . htmlspecialchars($e->getTraceAsString());
        echo '</pre>';
    }
}

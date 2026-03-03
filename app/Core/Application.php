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

        if ($config->get('app.installed', false)) {
            $db = new Database($config);
            $this->container->singleton(Database::class, fn() => $db);
        }

        $view = new View($this->rootPath . '/templates', $config, $session, $translator);
        $this->container->singleton(View::class, fn() => $view);

        $pluginManager = new PluginManager($this->rootPath . '/plugins', $this->container);
        $this->container->singleton(PluginManager::class, fn() => $pluginManager);

        if ($config->get('app.installed', false)) {
            $pluginManager->loadPlugins();
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

        http_response_code(500);

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

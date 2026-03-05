<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

class PluginManager
{
    private string $pluginsPath;
    private Container $container;
    private array $plugins = [];
    private array $hooks = [];
    private array $enabledPlugins = [];

    public function __construct(string $pluginsPath, Container $container)
    {
        $this->pluginsPath = $pluginsPath;
        $this->container   = $container;
    }

    public function loadPlugins(): void
    {
        if (!is_dir($this->pluginsPath)) {
            return;
        }

        $this->loadEnabledList();

        foreach (new \DirectoryIterator($this->pluginsPath) as $item) {
            if ($item->isDot() || !$item->isDir()) {
                continue;
            }

            $pluginName = $item->getFilename();
            if (!in_array($pluginName, $this->enabledPlugins, true)) {
                continue;
            }

            $this->loadPlugin($pluginName, $item->getPathname());
        }
    }

    private function loadEnabledList(): void
    {
        $listFile = $this->pluginsPath . '/enabled.json';
        if (file_exists($listFile)) {
            $data = json_decode(file_get_contents($listFile), true);
            $this->enabledPlugins = is_array($data) ? $data : [];
        }
    }

    private function loadPlugin(string $name, string $path): void
    {
        $manifestFile = $path . '/manifest.json';
        if (!file_exists($manifestFile)) {
            return;
        }

        $manifest = json_decode(file_get_contents($manifestFile), true);
        if (!is_array($manifest) || empty($manifest['name'])) {
            return;
        }

        $providerFile = $path . '/ServiceProvider.php';
        if (file_exists($providerFile)) {
            require_once $providerFile;
        }

        $serviceProviderClass = $manifest['service_provider']
            ?? 'Plugins\\' . str_replace('-', '', ucwords($name, '-')) . '\\ServiceProvider';

        if (class_exists($serviceProviderClass)) {
            $provider = new $serviceProviderClass();
            $provider->register($this);
        }

        $this->plugins[$name] = [
            'manifest' => $manifest,
            'path'     => $path,
            'enabled'  => true,
        ];
    }

    public function hook(string $hook, callable $callback, int $priority = 10): void
    {
        $this->hooks[$hook][] = [
            'callback' => $callback,
            'priority' => $priority,
        ];
        usort($this->hooks[$hook], fn($a, $b) => $a['priority'] <=> $b['priority']);
    }

    public function registerHook(string $hook, callable $callback, int $priority = 10): void
    {
        $this->hook($hook, $callback, $priority);
    }

    public function fireHook(string $hook, mixed $payload = null): mixed
    {
        if (empty($this->hooks[$hook])) {
            return $payload;
        }

        foreach ($this->hooks[$hook] as $entry) {
            $result = ($entry['callback'])($payload);
            if ($result !== null) {
                $payload = $result;
            }
        }

        return $payload;
    }

    public function registerRoutes(Router $router): void
    {
        foreach ($this->hooks['registerRoutes'] ?? [] as $entry) {
            ($entry['callback'])($router);
        }
    }

    public function getDashboardWidgets(array $context = []): array
    {
        $widgets = [];
        foreach ($this->hooks['dashboardWidgets'] ?? [] as $entry) {
            $result = ($entry['callback'])($context);
            if (is_array($result) && isset($result['id'])) {
                $widgets[] = $result;
            } elseif (is_string($result) && $result !== '') {
                $widgets[] = ['id' => 'plugin-' . count($widgets), 'title' => 'Plugin', 'content' => $result];
            }
        }
        return $widgets;
    }

    public function getPlugins(): array
    {
        return $this->plugins;
    }

    public function getAllAvailablePlugins(): array
    {
        if (!is_dir($this->pluginsPath)) {
            return [];
        }

        $available = [];
        foreach (new \DirectoryIterator($this->pluginsPath) as $item) {
            if ($item->isDot() || !$item->isDir()) {
                continue;
            }

            $slug         = $item->getFilename();
            $manifestFile = $item->getPathname() . '/manifest.json';

            if (file_exists($manifestFile)) {
                $manifest          = json_decode(file_get_contents($manifestFile), true);
                $available[$slug]  = [
                    'manifest' => $manifest,
                    'enabled'  => in_array($slug, $this->enabledPlugins, true),
                ];
            }
        }

        return $available;
    }

    public function enablePlugin(string $name): void
    {
        if (!in_array($name, $this->enabledPlugins, true)) {
            $this->enabledPlugins[] = $name;
            $this->saveEnabledList();
        }
    }

    public function disablePlugin(string $name): void
    {
        $this->enabledPlugins = array_values(
            array_filter($this->enabledPlugins, fn($p) => $p !== $name)
        );
        $this->saveEnabledList();
    }

    private function saveEnabledList(): void
    {
        $listFile = $this->pluginsPath . '/enabled.json';
        file_put_contents($listFile, json_encode($this->enabledPlugins, JSON_PRETTY_PRINT));
    }
}

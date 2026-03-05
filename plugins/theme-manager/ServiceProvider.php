<?php

declare(strict_types=1);

namespace Plugins\ThemeManager;

use App\Core\PluginManager;
use App\Core\Router;
use App\Core\View;
use App\Core\Application;

class ServiceProvider
{
    public function register(PluginManager $pluginManager): void
    {
        require_once __DIR__ . '/ThemeManager.php';
        require_once __DIR__ . '/ThemeController.php';

        $container = Application::getInstance()->getContainer();

        /* Register ThemeManager as singleton */
        $container->singleton(ThemeManager::class, fn() => new ThemeManager());

        /* Register template path */
        $view = $container->get(View::class);
        $view->addTemplatePath(__DIR__ . '/templates', 'theme-manager');

        /* Inject active theme CSS and slug as Twig globals */
        $themeManager = $container->get(ThemeManager::class);
        $view->addGlobal('active_theme_slug', $themeManager->getActive());
        $view->addGlobal('active_theme_css',  $themeManager->activeCssUrl());

        /* Register routes */
        $pluginManager->hook('registerRoutes', [$this, 'registerRoutes']);

        /* Nav item (admin only — checked in template) */
        $navItems   = $view->getTwig()->getGlobals()['plugin_nav_items'] ?? [];
        $navItems[] = [
            'label'     => 'Design',
            'href'      => '/design',
            'icon'      => '<svg width="18" height="18" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M12 2a10 10 0 0 1 7.07 17.07"/><circle cx="12" cy="12" r="3" fill="currentColor"/></svg>',
            'admin_only' => true,
        ];
        $view->addGlobal('plugin_nav_items', $navItems);
    }

    public function registerRoutes(Router $router): void
    {
        $router->get('/design',                              [ThemeController::class, 'index'],       ['auth']);
        /* Static routes FIRST — before wildcard {slug} routes */
        $router->post('/design/hochladen',                   [ThemeController::class, 'upload'],      ['auth']);
        $router->post('/design/{slug}/aktivieren',           [ThemeController::class, 'activate'],    ['auth']);
        $router->post('/design/{slug}/loeschen',             [ThemeController::class, 'delete'],      ['auth']);

        /* Serve theme assets publicly — two separate route segments */
        $router->get('/theme-assets/{slug}/{file}',          [ThemeController::class, 'serveAsset'],  []);
        $router->get('/theme-assets/{slug}/assets/{file}',   [ThemeController::class, 'serveSubAsset'], []);
    }
}

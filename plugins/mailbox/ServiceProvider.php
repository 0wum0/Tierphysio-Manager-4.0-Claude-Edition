<?php

declare(strict_types=1);

namespace Plugins\Mailbox;

use App\Core\PluginManager;
use App\Core\Router;
use App\Core\View;
use App\Core\Application;

class ServiceProvider
{
    public function register(PluginManager $pluginManager): void
    {
        require_once __DIR__ . '/MailboxService.php';
        require_once __DIR__ . '/MailboxController.php';

        $view = Application::getInstance()->getContainer()->get(View::class);
        $view->addTemplatePath(__DIR__ . '/templates', 'mailbox');

        $pluginManager->hook('registerRoutes', [$this, 'registerRoutes']);
        $pluginManager->hook('navItems', [$this, 'navItem']);

        $navItems   = $view->getTwig()->getGlobals()['plugin_nav_items'] ?? [];
        $navItems[] = [
            'label' => 'Mailbox',
            'href'  => '/mailbox',
            'icon'  => '<svg width="18" height="18" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" points="22,6 12,13 2,6"/></svg>',
        ];
        $view->addGlobal('plugin_nav_items', $navItems);
    }

    public function registerRoutes(Router $router): void
    {
        $router->get('/mailbox',                       [MailboxController::class, 'index'],   ['auth']);
        $router->get('/mailbox/posteingang',           [MailboxController::class, 'inbox'],   ['auth']);
        $router->get('/mailbox/gesendet',              [MailboxController::class, 'sent'],    ['auth']);
        $router->get('/mailbox/entwuerfe',             [MailboxController::class, 'drafts'],  ['auth']);
        $router->get('/mailbox/nachricht/{uid}',       [MailboxController::class, 'show'],    ['auth']);
        $router->get('/mailbox/neu',                   [MailboxController::class, 'compose'], ['auth']);
        $router->post('/mailbox/senden',               [MailboxController::class, 'send'],    ['auth']);
        $router->post('/mailbox/loeschen/{uid}',       [MailboxController::class, 'delete'],  ['auth']);
        $router->post('/mailbox/entwurf/speichern',    [MailboxController::class, 'saveDraft'], ['auth']);
        $router->get('/api/mailbox/check',             [MailboxController::class, 'apiCheck'], ['auth']);
    }

    public function navItem(array $context): array
    {
        return [
            'label' => 'Mailbox',
            'href'  => '/mailbox',
            'icon'  => '<svg width="16" height="16" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" points="22,6 12,13 2,6"/></svg>',
        ];
    }
}

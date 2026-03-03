<?php

declare(strict_types=1);

namespace Plugins\PatientInvite;

use App\Core\PluginManager;
use App\Core\Router;
use App\Core\View;
use App\Core\Application;

class ServiceProvider
{
    public function register(PluginManager $pluginManager): void
    {
        require_once __DIR__ . '/InviteRepository.php';
        require_once __DIR__ . '/InviteMailService.php';
        require_once __DIR__ . '/InviteController.php';

        $this->runMigrations();

        $view = Application::getInstance()->getContainer()->get(View::class);
        $view->addTemplatePath(__DIR__ . '/templates', 'patient-invite');

        $pluginManager->hook('registerRoutes', [$this, 'registerRoutes']);
        $pluginManager->hook('dashboardWidgets', [$this, 'dashboardWidget']);

        /* Sidebar nav item */
        $navItems   = $view->getTwig()->getGlobals()['plugin_nav_items'] ?? [];
        $navItems[] = [
            'label' => 'Einladungslinks',
            'href'  => '/einladungen',
            'icon'  => '<svg width="18" height="18" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.4 2 2 0 0 1 3.6 1.22h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.91a16 16 0 0 0 6.29 6.29l.95-.95a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>',
        ];
        $view->addGlobal('plugin_nav_items', $navItems);
    }

    public function registerRoutes(Router $router): void
    {
        /* Admin — requires auth */
        $router->get('/einladungen',              [InviteController::class, 'index'],      ['auth']);
        $router->post('/einladungen/senden',       [InviteController::class, 'send'],       ['auth']);
        $router->get('/einladungen/{id}/whatsapp', [InviteController::class, 'whatsappUrl'],['auth']);
        $router->get('/einladungen/{id}/link',     [InviteController::class, 'copyLink'],   ['auth']);
        $router->post('/einladungen/{id}/widerrufen', [InviteController::class, 'revoke'],  ['auth']);

        /* Public — magic link (no auth) */
        $router->get('/einladung/{token}',        [InviteController::class, 'landing'],    []);
        $router->post('/einladung/{token}',        [InviteController::class, 'submit'],     []);
        $router->get('/einladung/{token}/danke',   [InviteController::class, 'thankYou'],   []);
    }

    public function dashboardWidget(array $context): string
    {
        try {
            $db   = Application::getInstance()->getContainer()->get(\App\Core\Database::class);
            $repo = new InviteRepository($db);
            $offen = $repo->countByStatus('offen');
            $angenommen = $repo->countByStatus('angenommen');
        } catch (\Throwable) {
            return '';
        }

        $html  = '<div style="padding:1rem;">';
        $html .= '<div style="font-size:0.75rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);margin-bottom:0.75rem;display:flex;align-items:center;gap:0.5rem;">';
        $html .= '<svg width="13" height="13" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.4 2 2 0 0 1 3.6 1.22h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.91a16 16 0 0 0 6.29 6.29l.95-.95a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>';
        $html .= 'Einladungslinks</div>';
        $html .= '<div style="display:flex;gap:1rem;margin-bottom:0.75rem;">';
        $html .= '<div style="text-align:center;flex:1;"><div style="font-size:1.4rem;font-weight:700;color:' . ($offen > 0 ? '#f59e0b' : 'var(--accent)') . ';">' . $offen . '</div><div style="font-size:0.7rem;color:var(--text-muted);">Offen</div></div>';
        $html .= '<div style="text-align:center;flex:1;"><div style="font-size:1.4rem;font-weight:700;color:#22c55e;">' . $angenommen . '</div><div style="font-size:0.7rem;color:var(--text-muted);">Angenommen</div></div>';
        $html .= '</div>';
        $html .= '<a href="/einladungen" style="display:block;text-align:center;font-size:0.78rem;color:var(--accent);text-decoration:none;">Alle anzeigen →</a>';
        $html .= '</div>';
        return $html;
    }

    private function runMigrations(): void
    {
        try {
            $db           = Application::getInstance()->getContainer()->get(\App\Core\Database::class);
            $migrationDir = __DIR__ . '/migrations';
            if (!is_dir($migrationDir)) return;

            $files = glob($migrationDir . '/*.sql');
            if (!$files) return;
            sort($files);

            foreach ($files as $file) {
                $sql        = file_get_contents($file);
                $statements = array_filter(array_map('trim', explode(';', $sql)));
                foreach ($statements as $stmt) {
                    if (!empty($stmt)) {
                        try {
                            $db->execute($stmt);
                        } catch (\Throwable) {
                            /* Already exists — skip */
                        }
                    }
                }
            }
        } catch (\Throwable) {
            /* DB not ready yet */
        }
    }
}

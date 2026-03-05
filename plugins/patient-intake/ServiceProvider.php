<?php

declare(strict_types=1);

namespace Plugins\PatientIntake;

use App\Core\PluginManager;
use App\Core\Router;
use App\Core\View;
use App\Core\Application;

class ServiceProvider
{
    public function register(PluginManager $pluginManager): void
    {
        require_once __DIR__ . '/IntakeRepository.php';
        require_once __DIR__ . '/IntakeMailService.php';
        require_once __DIR__ . '/IntakeController.php';

        $this->runMigrations();

        $view = Application::getInstance()->getContainer()->get(View::class);
        $view->addTemplatePath(__DIR__ . '/templates', 'patient-intake');

        /* Routes */
        $pluginManager->hook('registerRoutes', [$this, 'registerRoutes']);

        /* Nav item in sidebar */
        $navItems   = $view->getTwig()->getGlobals()['plugin_nav_items'] ?? [];
        $navItems[] = [
            'label' => 'Eingangsmeldungen',
            'href'  => '/eingangsmeldungen',
            'icon'  => '<svg width="18" height="18" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" points="22,6 12,13 2,6"/></svg>',
        ];
        $view->addGlobal('plugin_nav_items', $navItems);

        /* Inject unread count into every page for the notification bell */
        $this->injectNotificationCount($view);

        /* Dashboard widget */
        $pluginManager->hook('dashboardWidgets', [$this, 'dashboardWidget']);
    }

    public function registerRoutes(Router $router): void
    {
        /* Public — no auth */
        $router->get('/anmeldung',        [IntakeController::class, 'form'],     []);
        $router->post('/anmeldung',        [IntakeController::class, 'submit'],   []);
        $router->get('/anmeldung/danke',   [IntakeController::class, 'thankYou'], []);

        /* Admin — requires auth */
        $router->get('/eingangsmeldungen',              [IntakeController::class, 'inbox'],        ['auth']);
        $router->get('/eingangsmeldungen/{id}',         [IntakeController::class, 'show'],         ['auth']);
        $router->post('/eingangsmeldungen/{id}/akzeptieren', [IntakeController::class, 'accept'],  ['auth']);
        $router->post('/eingangsmeldungen/{id}/ablehnen',    [IntakeController::class, 'reject'],  ['auth']);
        $router->post('/eingangsmeldungen/{id}/status',      [IntakeController::class, 'updateStatus'], ['auth']);

        /* API — requires auth */
        $router->get('/api/intake/notifications', [IntakeController::class, 'apiNotifications'], ['auth']);

        /* Photo serving (intake photos) */
        $router->get('/intake/foto/{file}', [IntakeController::class, 'servePhoto'], ['auth']);
    }

    public function dashboardWidget(array $context): array
    {
        try {
            $db     = Application::getInstance()->getContainer()->get(\App\Core\Database::class);
            $repo   = new IntakeRepository($db);
            $neu    = $repo->countByStatus('neu');
            $ib     = $repo->countByStatus('in_bearbeitung');
            $latest = $repo->getLatestUnread(3);
        } catch (\Throwable) {
            return [];
        }

        $html  = '<div class="d-flex gap-3 mb-3">';
        $html .= '<div class="text-center flex-fill"><div class="fs-3 fw-800" style="color:' . ($neu > 0 ? '#ef4444' : 'var(--bs-primary)') . '">' . $neu . '</div><div class="fs-nano text-muted">Neu</div></div>';
        $html .= '<div class="text-center flex-fill"><div class="fs-3 fw-800" style="color:var(--bs-primary)">' . $ib . '</div><div class="fs-nano text-muted">In Bearb.</div></div>';
        $html .= '</div>';

        $html .= '<div class="list-group list-group-flush">';
        if (empty($latest)) {
            $html .= '<div class="list-group-item text-muted fs-sm">Keine offenen Meldungen.</div>';
        } else {
            foreach ($latest as $item) {
                $name  = htmlspecialchars($item['patient_name']);
                $owner = htmlspecialchars($item['owner_first_name'] . ' ' . $item['owner_last_name']);
                $time  = date('d.m. H:i', strtotime($item['created_at']));
                $html .= '<a href="/eingangsmeldungen" class="list-group-item list-group-item-action d-flex align-items-center gap-2 px-0 py-2">';
                $html .= '<span style="width:9px;height:9px;border-radius:50%;background:#ef4444;flex-shrink:0;"></span>';
                $html .= '<span class="text-muted fs-nano" style="flex-shrink:0;min-width:70px;">' . $time . '</span>';
                $html .= '<span class="fs-sm fw-500 text-truncate">' . $name . ' <span class="text-muted">· ' . $owner . '</span></span>';
                $html .= '</a>';
            }
        }
        $html .= '</div>';
        $html .= '<a href="/eingangsmeldungen" class="btn btn-sm btn-outline-primary w-100 mt-2">Alle anzeigen →</a>';

        return [
            'id'      => 'panel-widget-intake',
            'title'   => 'Eingangsmeldungen',
            'icon'    => '<svg width="14" height="14" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" points="22,6 12,13 2,6"/></svg>',
            'content' => $html,
            'col'     => 'col-xl-4 col-lg-5 col-12',
        ];
    }

    private function injectNotificationCount(View $view): void
    {
        try {
            $db   = Application::getInstance()->getContainer()->get(\App\Core\Database::class);
            $repo = new IntakeRepository($db);
            $view->addGlobal('intake_unread_count', $repo->countUnread());
            $view->addGlobal('intake_latest', $repo->getLatestUnread(5));
        } catch (\Throwable) {
            $view->addGlobal('intake_unread_count', 0);
            $view->addGlobal('intake_latest', []);
        }
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
                            /* Table already exists — skip */
                        }
                    }
                }
            }
        } catch (\Throwable) {
            /* DB not available yet (installer phase) */
        }
    }
}

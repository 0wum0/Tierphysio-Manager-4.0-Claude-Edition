<?php

declare(strict_types=1);

namespace Plugins\Calendar;

use App\Core\PluginManager;
use App\Core\Router;
use App\Core\View;
use App\Core\Application;

class ServiceProvider
{
    public function register(PluginManager $pluginManager): void
    {
        require_once __DIR__ . '/AppointmentRepository.php';
        require_once __DIR__ . '/CalendarController.php';
        require_once __DIR__ . '/ReminderService.php';

        /* Run plugin migration if needed */
        $this->runMigrations();

        /* Register plugin template path under @calendar namespace */
        $view = Application::getInstance()->getContainer()->get(View::class);
        $view->addTemplatePath(__DIR__ . '/templates', 'calendar');

        /* Register routes */
        $pluginManager->hook('registerRoutes', [$this, 'registerRoutes']);

        /* Dashboard widget */
        $pluginManager->hook('dashboardWidgets', [$this, 'dashboardWidget']);

        /* Nav item - register as global Twig variable */
        $navItems = $view->getTwig()->getGlobals()['plugin_nav_items'] ?? [];
        $navItems[] = [
            'label' => 'Kalender',
            'href'  => '/kalender',
            'icon'  => '<svg width="18" height="18" fill="none" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2"/><line x1="16" y1="2" x2="16" y2="6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><line x1="8" y1="2" x2="8" y2="6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><line x1="3" y1="10" x2="21" y2="10" stroke="currentColor" stroke-width="2"/></svg>',
        ];
        $view->addGlobal('plugin_nav_items', $navItems);

        /* Nav item hook (for other plugins) */
        $pluginManager->hook('navItems', [$this, 'navItem']);
    }

    public function registerRoutes(Router $router): void
    {
        $router->get('/kalender',                    [CalendarController::class, 'index'],          ['auth']);
        $router->get('/kalender/warteliste',         [CalendarController::class, 'waitlist'],        ['auth']);
        $router->post('/kalender/warteliste',        [CalendarController::class, 'waitlistStore'],   ['auth']);
        $router->post('/kalender/warteliste/{id}/loeschen', [CalendarController::class, 'waitlistDelete'], ['auth']);
        $router->post('/kalender/warteliste/{id}/einplanen', [CalendarController::class, 'waitlistSchedule'], ['auth']);
        $router->get('/kalender/statistiken',        [CalendarController::class, 'stats'],           ['auth']);
        $router->get('/kalender/ical-export',        [CalendarController::class, 'icalExport'],      ['auth']);
        $router->post('/kalender/ical-import',       [CalendarController::class, 'icalImport'],      ['auth']);
        $router->post('/kalender/{id}/rechnung',     [CalendarController::class, 'createInvoice'],   ['auth']);

        /* Cron endpoint - no auth middleware, secured by token */
        $router->get('/kalender/cron/erinnerungen',  [CalendarController::class, 'cronReminders'],   []);

        /* JSON API */
        $router->get('/api/kalender/events',         [CalendarController::class, 'apiEvents'],       ['auth']);
        $router->get('/api/kalender/events/{id}',    [CalendarController::class, 'apiShow'],         ['auth']);
        $router->post('/api/kalender/events',        [CalendarController::class, 'apiStore'],        ['auth']);
        $router->post('/api/kalender/events/{id}',   [CalendarController::class, 'apiUpdate'],       ['auth']);
        $router->post('/api/kalender/events/{id}/reschedule', [CalendarController::class, 'apiReschedule'], ['auth']);
        $router->post('/api/kalender/events/{id}/loeschen', [CalendarController::class, 'apiDelete'], ['auth']);
    }

    public function dashboardWidget(array $context): array
    {
        try {
            $db   = Application::getInstance()->getContainer()->get(\App\Core\Database::class);
            $repo = new AppointmentRepository($db);
            $upcoming = $repo->findUpcoming(5);
            $stats    = $repo->getStats();
        } catch (\Throwable) {
            return [];
        }

        $html  = '<div class="d-flex gap-3 mb-3">';
        $html .= '<div class="text-center flex-fill"><div class="fs-3 fw-800" style="color:var(--bs-primary)">' . ($stats['today'] ?? 0) . '</div><div class="fs-nano text-muted">Heute</div></div>';
        $html .= '<div class="text-center flex-fill"><div class="fs-3 fw-800" style="color:var(--bs-primary)">' . ($stats['upcoming'] ?? 0) . '</div><div class="fs-nano text-muted">Geplant</div></div>';
        $html .= '</div>';

        $html .= '<div class="list-group list-group-flush">';
        if (empty($upcoming)) {
            $html .= '<div class="list-group-item text-muted fs-sm">Keine Termine geplant.</div>';
        } else {
            foreach ($upcoming as $a) {
                $color   = htmlspecialchars($a['treatment_type_color'] ?? '#4f7cff');
                $time    = date('d.m. H:i', strtotime($a['start_at']));
                $title   = htmlspecialchars($a['title']);
                $patient = $a['patient_name'] ? ' <span class="text-muted">· ' . htmlspecialchars($a['patient_name']) . '</span>' : '';
                $html .= '<a href="/kalender" class="list-group-item list-group-item-action d-flex align-items-center gap-2 px-0 py-2">';
                $html .= '<span style="width:9px;height:9px;border-radius:50%;background:' . $color . ';flex-shrink:0;"></span>';
                $html .= '<span class="text-muted fs-nano" style="flex-shrink:0;min-width:70px;">' . $time . '</span>';
                $html .= '<span class="fs-sm fw-500 text-truncate">' . $title . $patient . '</span>';
                $html .= '</a>';
            }
        }
        $html .= '</div>';
        $html .= '<a href="/kalender" class="btn btn-sm btn-outline-primary w-100 mt-2">Kalender öffnen →</a>';

        return [
            'id'      => 'panel-widget-calendar',
            'title'   => 'Nächste Termine',
            'icon'    => '<svg width="14" height="14" fill="none" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2"/><line x1="16" y1="2" x2="16" y2="6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><line x1="8" y1="2" x2="8" y2="6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><line x1="3" y1="10" x2="21" y2="10" stroke="currentColor" stroke-width="2"/></svg>',
            'content' => $html,
            'col'     => 'col-xl-4 col-lg-5 col-12',
        ];
    }

    public function navItem(array $context): array
    {
        return [
            'label' => 'Kalender',
            'href'  => '/kalender',
            'icon'  => '<svg width="16" height="16" fill="none" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2"/><line x1="16" y1="2" x2="16" y2="6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><line x1="8" y1="2" x2="8" y2="6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><line x1="3" y1="10" x2="21" y2="10" stroke="currentColor" stroke-width="2"/></svg>',
        ];
    }

    private function runMigrations(): void
    {
        try {
            $db = Application::getInstance()->getContainer()->get(\App\Core\Database::class);
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
                            /* Table already exists - skip */
                        }
                    }
                }
            }
        } catch (\Throwable) {
            /* DB not available yet (installer phase) */
        }
    }
}

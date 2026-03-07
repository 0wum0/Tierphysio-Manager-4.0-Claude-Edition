<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Config;
use App\Core\Session;
use App\Core\Translator;
use App\Core\View;
use App\Core\PluginManager;
use App\Services\DashboardService;

class DashboardController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        Config $config,
        Translator $translator,
        private readonly DashboardService $dashboardService,
        private readonly PluginManager $pluginManager
    ) {
        parent::__construct($view, $session, $config, $translator);
    }

    public function index(array $params = []): void
    {
        $stats   = $this->dashboardService->getStats();
        $user    = $this->session->getUser();
        $widgets = $this->pluginManager->getDashboardWidgets(['user' => $user]);

        $user           = $this->session->getUser();
        $savedLayout    = $user ? $this->dashboardService->loadLayout((int)$user['id']) : null;

        $birthdays = $this->dashboardService->getUpcomingBirthdays(14);

        $this->render('dashboard/index.twig', [
            'page_title'          => $this->translator->trans('nav.dashboard'),
            'stats'               => $stats,
            'plugin_widgets'      => $widgets,
            'saved_layout'        => $savedLayout,
            'upcoming_birthdays'  => $birthdays,
        ]);
    }

    public function chartData(array $params = []): void
    {
        $type = $this->get('type', 'weekly');
        $data = $this->dashboardService->getChartData($type);
        $this->json($data);
    }

    public function saveLayout(array $params = []): void
    {
        $user = $this->session->getUser();
        if (!$user) { $this->json(['ok' => false], 401); return; }

        $body    = (string)file_get_contents('php://input');
        $payload = json_decode($body, true);

        if (!is_array($payload) || !array_key_exists('layout', $payload)) {
            $this->json(['ok' => false, 'error' => 'invalid payload'], 400);
            return;
        }

        if ($payload['layout'] === null) {
            $this->dashboardService->deleteLayout((int)$user['id']);
        } elseif (is_array($payload['layout'])) {
            $this->dashboardService->saveLayout((int)$user['id'], $payload['layout']);
        }
        $this->json(['ok' => true]);
    }

    public function loadLayout(array $params = []): void
    {
        $user = $this->session->getUser();
        if (!$user) { $this->json(['layout' => null], 401); return; }

        $layout = $this->dashboardService->loadLayout((int)$user['id']);
        $this->json(['layout' => $layout]);
    }
}

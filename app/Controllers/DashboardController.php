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

        $this->render('dashboard/index.twig', [
            'page_title'     => $this->translator->trans('nav.dashboard'),
            'stats'          => $stats,
            'plugin_widgets' => $widgets,
        ]);
    }

    public function chartData(array $params = []): void
    {
        $type = $this->get('type', 'weekly');
        $data = $this->dashboardService->getChartData($type);
        $this->json($data);
    }
}

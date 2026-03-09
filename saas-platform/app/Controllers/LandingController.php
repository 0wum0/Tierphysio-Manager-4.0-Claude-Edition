<?php

declare(strict_types=1);

namespace Saas\Controllers;

use Saas\Core\Controller;
use Saas\Core\View;
use Saas\Core\Session;
use Saas\Repositories\PlanRepository;
use Saas\Core\Config;

class LandingController extends Controller
{
    public function __construct(
        View                  $view,
        Session               $session,
        private PlanRepository $planRepo,
        private Config         $config
    ) {
        parent::__construct($view, $session);
    }

    public function index(array $params = []): void
    {
        $plans = $this->planRepo->allActive();

        $practiceUrl = rtrim($this->config->get('practice.url', ''), '/');
        $demoEmail   = $_ENV['DEMO_EMAIL']    ?? 'info@demo.de';
        $demoPass    = $_ENV['DEMO_PASSWORD'] ?? 'admin123456';

        $this->render('landing/index.twig', [
            'page_title'   => 'Tierphysio Manager — Die Praxissoftware für Tierphysiotherapeuten',
            'plans'        => $plans,
            'practice_url' => $practiceUrl,
            'demo_email'   => $demoEmail,
            'demo_password'=> $demoPass,
        ]);
    }
}

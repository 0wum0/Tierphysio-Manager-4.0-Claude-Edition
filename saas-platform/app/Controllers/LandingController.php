<?php

declare(strict_types=1);

namespace Saas\Controllers;

use Saas\Core\Controller;
use Saas\Core\View;
use Saas\Core\Session;
use Saas\Repositories\PlanRepository;

class LandingController extends Controller
{
    public function __construct(
        View                  $view,
        Session               $session,
        private PlanRepository $planRepo
    ) {
        parent::__construct($view, $session);
    }

    public function index(array $params = []): void
    {
        $plans = $this->planRepo->allActive();

        $this->render('landing/index.twig', [
            'page_title' => 'Tierphysio Manager — Die Praxissoftware für Tierphysiotherapeuten',
            'plans'      => $plans,
        ]);
    }
}

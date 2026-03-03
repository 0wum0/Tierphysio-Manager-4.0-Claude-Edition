<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Session;
use App\Core\View;

class AdminMiddleware
{
    public function __construct(
        private Session $session,
        private View $view
    ) {}

    public function handle(callable $next): void
    {
        if (!$this->session->isLoggedIn()) {
            header('Location: /login');
            exit;
        }

        if (!$this->session->isAdmin()) {
            http_response_code(403);
            $this->view->render('errors/403.twig', []);
            exit;
        }

        $next();
    }
}

<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Session;

class GuestMiddleware
{
    public function __construct(private Session $session) {}

    public function handle(callable $next): void
    {
        if ($this->session->isLoggedIn()) {
            header('Location: /dashboard');
            exit;
        }
        $next();
    }
}

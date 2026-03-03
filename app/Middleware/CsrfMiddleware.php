<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Session;

class CsrfMiddleware
{
    public function __construct(private Session $session) {}

    public function handle(callable $next): void
    {
        if (in_array(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $token = $_POST['_csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
            if (!$this->session->validateCsrfToken($token)) {
                http_response_code(403);
                echo json_encode(['error' => 'CSRF token mismatch']);
                exit;
            }
        }
        $next();
    }
}

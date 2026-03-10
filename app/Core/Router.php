<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

class Router
{
    private array $routes = [];
    private Container $container;
    private array $middlewareGroups = [];

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function get(string $path, string|array $handler, array $middleware = []): void
    {
        $this->addRoute('GET', $path, $handler, $middleware);
    }

    public function post(string $path, string|array $handler, array $middleware = []): void
    {
        $this->addRoute('POST', $path, $handler, $middleware);
    }

    public function put(string $path, string|array $handler, array $middleware = []): void
    {
        $this->addRoute('PUT', $path, $handler, $middleware);
    }

    public function delete(string $path, string|array $handler, array $middleware = []): void
    {
        $this->addRoute('DELETE', $path, $handler, $middleware);
    }

    public function patch(string $path, string|array $handler, array $middleware = []): void
    {
        $this->addRoute('PATCH', $path, $handler, $middleware);
    }

    private function addRoute(string $method, string $path, string|array $handler, array $middleware = []): void
    {
        $pattern = $this->pathToPattern($path);
        $this->routes[] = [
            'method'     => strtoupper($method),
            'path'       => $path,
            'pattern'    => $pattern,
            'handler'    => $handler,
            'middleware' => $middleware,
        ];
    }

    private function pathToPattern(string $path): string
    {
        $pattern = preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', '(?P<$1>[^/]+)', $path);
        return '#^' . $pattern . '$#';
    }

    public function loadRoutes(string $file): void
    {
        if (!file_exists($file)) {
            throw new RuntimeException("Routes file not found: {$file}");
        }
        $router = $this;
        require $file;
    }

    public function dispatch(): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $uri    = rtrim($uri, '/') ?: '/';

        if ($method === 'POST' && isset($_POST['_method'])) {
            $method = strtoupper($_POST['_method']);
        }

        // Strip tenant slug prefix from URI for path-based multi-tenancy
        // e.g. /tpm6/dashboard → /dashboard
        if (isset($this->container)) {
            try {
                $resolver = $this->container->get(\App\Core\TenantResolver::class);
                $slug     = $resolver->getSlug();
                if ($slug !== '' && str_starts_with($uri, '/' . $slug)) {
                    $stripped = substr($uri, strlen('/' . $slug));
                    $uri      = $stripped === '' ? '/' : $stripped;
                }
            } catch (\Throwable) {}
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (preg_match($route['pattern'], $uri, $matches)) {
                $params = array_filter($matches, fn($k) => is_string($k), ARRAY_FILTER_USE_KEY);

                $this->runMiddleware($route['middleware'], function () use ($route, $params) {
                    $this->callHandler($route['handler'], $params);
                });
                return;
            }
        }

        $this->notFound();
    }

    private function runMiddleware(array $middleware, callable $next): void
    {
        if (empty($middleware)) {
            $next();
            return;
        }

        $middlewareName = array_shift($middleware);
        $middlewareClass = $this->resolveMiddleware($middlewareName);
        $instance = $this->container->get($middlewareClass);

        $instance->handle(function () use ($middleware, $next) {
            $this->runMiddleware($middleware, $next);
        });
    }

    private function resolveMiddleware(string $name): string
    {
        $map = [
            'auth'  => \App\Middleware\AuthMiddleware::class,
            'guest' => \App\Middleware\GuestMiddleware::class,
            'admin' => \App\Middleware\AdminMiddleware::class,
            'csrf'  => \App\Middleware\CsrfMiddleware::class,
        ];

        return $map[$name] ?? $name;
    }

    private function callHandler(string|array $handler, array $params): void
    {
        if (is_string($handler) && str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler);
            $controller = $this->container->get($class);
            $controller->$method($params);
            return;
        }

        if (is_array($handler)) {
            [$class, $method] = $handler;
            $controller = $this->container->get($class);
            $controller->$method($params);
            return;
        }

        if (is_callable($handler)) {
            $handler($params);
            return;
        }

        throw new RuntimeException("Invalid route handler.");
    }

    private function notFound(): void
    {
        http_response_code(404);
        $view = $this->container->get(View::class);
        $view->render('errors/404.twig', []);
    }
}

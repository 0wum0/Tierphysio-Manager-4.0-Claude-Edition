<?php

declare(strict_types=1);

namespace Saas\Core;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;
use Twig\TwigFilter;

class View
{
    private Environment $twig;
    private array $globals = [];

    public function __construct(
        private string  $templatePath,
        private Config  $config,
        private Session $session
    ) {
        $loader = new FilesystemLoader($templatePath);
        $this->twig = new Environment($loader, [
            'cache'       => $config->get('app.env') === 'production'
                             ? $config->getRootPath() . '/storage/cache/twig'
                             : false,
            'debug'       => $config->get('app.debug', false),
            'auto_reload' => true,
        ]);

        $this->registerGlobals();
        $this->registerFunctions();
    }

    private function registerGlobals(): void
    {
        $this->twig->addGlobal('app_name',     $this->config->get('app.name', 'Tierphysio SaaS'));
        $this->twig->addGlobal('app_url',      $this->config->get('app.url', ''));
        $this->twig->addGlobal('practice_url', rtrim($this->config->get('practice.url', ''), '/'));
        $this->twig->addGlobal('flash_success', $this->session->getFlash('success'));
        $this->twig->addGlobal('flash_error',   $this->session->getFlash('error'));
        $this->twig->addGlobal('flash_info',    $this->session->getFlash('info'));
        $this->twig->addGlobal('csrf_token',    $this->session->csrf());
        $this->twig->addGlobal('auth_user',     $this->session->get('saas_user'));
        $this->twig->addGlobal('auth_role',     $this->session->get('saas_role'));
    }

    private function registerFunctions(): void
    {
        $this->twig->addFunction(new TwigFunction('asset', function (string $path): string {
            $base = rtrim($this->config->get('app.url', ''), '/');
            return $base . '/assets/' . ltrim($path, '/');
        }));

        $this->twig->addFunction(new TwigFunction('url', function (string $path): string {
            $base = rtrim($this->config->get('app.url', ''), '/');
            return $base . '/' . ltrim($path, '/');
        }));

        $this->twig->addFunction(new TwigFunction('csrf_field', function (): string {
            $token = $this->session->csrf();
            return '<input type="hidden" name="_csrf" value="' . htmlspecialchars($token) . '">';
        }, ['is_safe' => ['html']]));

        // Filters
        $this->twig->addFilter(new TwigFilter('json_decode', function (mixed $value): mixed {
            if (is_array($value)) return $value;
            if (!is_string($value)) return [];
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }));

        $this->twig->addFilter(new TwigFilter('number_format', function (mixed $value, int $decimals = 2, string $decPoint = ',', string $thousandsSep = '.'): string {
            return number_format((float)$value, $decimals, $decPoint, $thousandsSep);
        }));
    }

    public function render(string $template, array $data = []): string
    {
        return $this->twig->render($template, $data);
    }

    public function addGlobal(string $key, mixed $value): void
    {
        $this->twig->addGlobal($key, $value);
    }

    public function addTemplatePath(string $path, string $namespace = '__main__'): void
    {
        /** @var FilesystemLoader $loader */
        $loader = $this->twig->getLoader();
        if ($loader instanceof FilesystemLoader) {
            $loader->addPath($path, $namespace);
        }
    }

    public function getTwig(): Environment
    {
        return $this->twig;
    }
}

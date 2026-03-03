<?php

declare(strict_types=1);

namespace App\Core;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;
use Twig\TwigFilter;
use Twig\Extension\DebugExtension;

class View
{
    private Environment $twig;
    private Config $config;
    private Session $session;
    private Translator $translator;

    public function __construct(
        string $templatesPath,
        Config $config,
        Session $session,
        Translator $translator
    ) {
        $this->config     = $config;
        $this->session    = $session;
        $this->translator = $translator;

        $loader = new FilesystemLoader($templatesPath);
        $this->twig = new Environment($loader, [
            'cache'       => $config->get('app.env') === 'production'
                ? ROOT_PATH . '/storage/cache/twig'
                : false,
            'debug'       => $config->get('app.debug', false),
            'auto_reload' => true,
        ]);

        if ($config->get('app.debug', false)) {
            $this->twig->addExtension(new DebugExtension());
        }

        $this->registerGlobals();
        $this->registerFunctions();
        $this->registerFilters();
    }

    private function registerGlobals(): void
    {
        $this->twig->addGlobal('app_name', $this->config->get('app.name'));
        $this->twig->addGlobal('app_version', $this->config->get('app.version'));
        $this->twig->addGlobal('current_locale', $this->translator->getLocale());
        $this->twig->addGlobal('session', $this->session);
        $this->twig->addGlobal('flash', $this->session->allFlash());
        $this->twig->addGlobal('current_user', $this->session->getUser());
        $this->twig->addGlobal('csrf_token', $this->session->generateCsrfToken());
        $this->twig->addGlobal('theme', $this->session->get('theme', 'dark'));
    }

    private function registerFunctions(): void
    {
        $this->twig->addFunction(new TwigFunction('t', function (string $key, array $replace = []) {
            return $this->translator->trans($key, $replace);
        }));

        $this->twig->addFunction(new TwigFunction('url', function (string $path) {
            $base = $this->config->get('app.url', '');
            return rtrim($base, '/') . '/' . ltrim($path, '/');
        }));

        $this->twig->addFunction(new TwigFunction('asset', function (string $path) {
            $base = $this->config->get('app.url', '');
            return rtrim($base, '/') . '/assets/' . ltrim($path, '/');
        }));

        $this->twig->addFunction(new TwigFunction('route', function (string $path) {
            return '/' . ltrim($path, '/');
        }));

        $this->twig->addFunction(new TwigFunction('csrf_field', function () {
            $token = $this->session->generateCsrfToken();
            return '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars($token) . '">';
        }, ['is_safe' => ['html']]));

        $this->twig->addFunction(new TwigFunction('flash', function (string $key, mixed $default = null) {
            return $this->session->getFlash($key, $default);
        }));

        $this->twig->addFunction(new TwigFunction('has_flash', function (string $key) {
            return $this->session->hasFlash($key);
        }));

        $this->twig->addFunction(new TwigFunction('is_active', function (string $path) {
            $current = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
            return str_starts_with($current, $path) ? 'active' : '';
        }));
    }

    private function registerFilters(): void
    {
        $this->twig->addFilter(new TwigFilter('money', function (mixed $amount) {
            return number_format((float)$amount, 2, ',', '.') . ' €';
        }));

        $this->twig->addFilter(new TwigFilter('date_de', function (mixed $date) {
            if (empty($date)) return '-';
            $ts = is_numeric($date) ? (int)$date : strtotime((string)$date);
            return date('d.m.Y', $ts);
        }));

        $this->twig->addFilter(new TwigFilter('datetime_de', function (mixed $date) {
            if (empty($date)) return '-';
            $ts = is_numeric($date) ? (int)$date : strtotime((string)$date);
            return date('d.m.Y H:i', $ts);
        }));

        $this->twig->addFilter(new TwigFilter('truncate', function (string $value, int $length = 100) {
            if (mb_strlen($value) <= $length) return $value;
            return mb_substr($value, 0, $length) . '…';
        }));

        $this->twig->addFilter(new TwigFilter('initials', function (string $name) {
            $parts = explode(' ', $name);
            $initials = '';
            foreach (array_slice($parts, 0, 2) as $part) {
                $initials .= mb_strtoupper(mb_substr($part, 0, 1));
            }
            return $initials;
        }));
    }

    public function render(string $template, array $data = []): void
    {
        echo $this->fetch($template, $data);
    }

    public function fetch(string $template, array $data = []): string
    {
        return $this->twig->render($template, $data);
    }

    public function getTwig(): Environment
    {
        return $this->twig;
    }
}

<?php

declare(strict_types=1);

namespace App\Core;

abstract class Controller
{
    protected View $view;
    protected Session $session;
    protected Config $config;
    protected Translator $translator;

    public function __construct(
        View $view,
        Session $session,
        Config $config,
        Translator $translator
    ) {
        $this->view       = $view;
        $this->session    = $session;
        $this->config     = $config;
        $this->translator = $translator;
    }

    protected function render(string $template, array $data = []): void
    {
        $this->view->render($template, $data);
    }

    protected function redirect(string $url): void
    {
        header('Location: ' . $url);
        exit;
    }

    protected function redirectBack(string $fallback = '/'): void
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? $fallback;
        $this->redirect($referer);
    }

    protected function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    protected function input(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? $_GET[$key] ?? $default;
    }

    protected function post(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? $default;
    }

    protected function get(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    protected function all(): array
    {
        return array_merge($_GET, $_POST);
    }

    protected function flash(string $type, string $message): void
    {
        $this->session->flash($type, $message);
    }

    protected function isPost(): bool
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }

    protected function isAjax(): bool
    {
        return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
    }

    protected function validateCsrf(): void
    {
        $token = $this->post('_csrf_token') ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!$this->session->validateCsrfToken($token)) {
            http_response_code(403);
            $this->flash('error', $this->translator->trans('errors.csrf_invalid'));
            $this->redirectBack();
        }
    }

    protected function requireRole(string $role): void
    {
        $user = $this->session->getUser();
        if (!$user || $user['role'] !== $role) {
            http_response_code(403);
            $this->render('errors/403.twig');
            exit;
        }
    }

    protected function requireAdmin(): void
    {
        $this->requireRole('admin');
    }

    protected function abort(int $code): void
    {
        http_response_code($code);
        $this->render("errors/{$code}.twig", []);
        exit;
    }

    protected function sanitize(string $value): string
    {
        return htmlspecialchars(strip_tags(trim($value)), ENT_QUOTES, 'UTF-8');
    }

    protected function uploadFile(string $field, string $destination, array $allowedTypes = []): string|false
    {
        if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
            return false;
        }

        $file     = $_FILES[$field];
        $maxSize  = $this->config->get('storage.max_size', 10485760);

        if ($file['size'] > $maxSize) {
            return false;
        }

        if (!empty($allowedTypes)) {
            $finfo    = new \finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($file['tmp_name']);
            if (!in_array($mimeType, $allowedTypes, true)) {
                return false;
            }
        }

        $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = bin2hex(random_bytes(16)) . '.' . strtolower($ext);
        $fullPath = $destination . '/' . $filename;

        if (!is_dir($destination)) {
            mkdir($destination, 0755, true);
        }

        if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
            return false;
        }

        return $filename;
    }
}

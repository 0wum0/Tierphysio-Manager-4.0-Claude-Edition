<?php

declare(strict_types=1);

namespace App\Core;

class Session
{
    private Config $config;
    private bool $started = false;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function start(): void
    {
        if ($this->started || session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;
            return;
        }

        $lifetime = $this->config->get('session.lifetime', 120) * 60;
        $secure   = $this->config->get('session.secure', false);

        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_name('TIERPHYSIO_SESSION');
        session_start();

        $this->regenerateIfNeeded();
        $this->started = true;
    }

    private function regenerateIfNeeded(): void
    {
        if (!isset($_SESSION['_last_regeneration'])) {
            $_SESSION['_last_regeneration'] = time();
            return;
        }

        if (time() - $_SESSION['_last_regeneration'] > 1800) {
            $this->regenerate();
        }
    }

    public function regenerate(): void
    {
        session_regenerate_id(true);
        $_SESSION['_last_regeneration'] = time();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function delete(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function flash(string $key, mixed $value): void
    {
        $_SESSION['_flash'][$key] = $value;
    }

    public function getFlash(string $key, mixed $default = null): mixed
    {
        $value = $_SESSION['_flash'][$key] ?? $default;
        unset($_SESSION['_flash'][$key]);
        return $value;
    }

    public function hasFlash(string $key): bool
    {
        return isset($_SESSION['_flash'][$key]);
    }

    public function allFlash(): array
    {
        $flash = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $flash;
    }

    public function destroy(): void
    {
        $_SESSION = [];
        session_destroy();
        $this->started = false;
    }

    public function generateCsrfToken(): string
    {
        if (!isset($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf_token'];
    }

    public function validateCsrfToken(string $token): bool
    {
        return isset($_SESSION['_csrf_token'])
            && hash_equals($_SESSION['_csrf_token'], $token);
    }

    public function regenerateCsrfToken(): string
    {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        return $_SESSION['_csrf_token'];
    }

    public function isLoggedIn(): bool
    {
        return $this->has('user_id');
    }

    public function getUser(): ?array
    {
        if (!$this->has('user_id')) {
            return null;
        }
        return [
            'id'         => $this->get('user_id'),
            'name'       => $this->get('user_name'),
            'email'      => $this->get('user_email'),
            'role'       => $this->get('user_role'),
            'last_login' => $this->get('user_last_login'),
        ];
    }

    public function setUser(array $user): void
    {
        $this->set('user_id', $user['id']);
        $this->set('user_name', $user['name']);
        $this->set('user_email', $user['email']);
        $this->set('user_role', $user['role']);
        $this->regenerate();
    }

    public function isAdmin(): bool
    {
        return $this->get('user_role') === 'admin';
    }
}

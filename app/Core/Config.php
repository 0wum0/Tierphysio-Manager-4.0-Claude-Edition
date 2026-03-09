<?php

declare(strict_types=1);

namespace App\Core;

class Config
{
    private array $config = [];
    private string $rootPath;

    public function __construct(string $rootPath)
    {
        $this->rootPath = $rootPath;
        $this->loadDefaults();
    }

    private function loadDefaults(): void
    {
        $this->config = [
            'app' => [
                'name'      => $_ENV['APP_NAME'] ?? 'Tierphysio Manager',
                'version'   => $_ENV['APP_VERSION'] ?? '3.0.0',
                'env'       => $_ENV['APP_ENV'] ?? 'production',
                'debug'     => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'url'       => $_ENV['APP_URL'] ?? 'http://localhost',
                'locale'    => $_ENV['APP_LOCALE'] ?? 'de',
                'installed' => filter_var($_ENV['INSTALLED'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'key'       => $_ENV['APP_KEY'] ?? '',
            ],
            'db' => [
                'host'     => $_ENV['DB_HOST'] ?? 'localhost',
                'port'     => (int)($_ENV['DB_PORT'] ?? 3306),
                'database' => $_ENV['DB_DATABASE'] ?? 'tierphysio',
                'username' => $_ENV['DB_USERNAME'] ?? 'root',
                'password' => $_ENV['DB_PASSWORD'] ?? '',
                'version'  => (int)($_ENV['DB_VERSION'] ?? 0),
                'prefix'   => $_ENV['TABLE_PREFIX'] ?? '',
            ],
            'session' => [
                'lifetime' => (int)($_ENV['SESSION_LIFETIME'] ?? 10080),
                'secure'   => filter_var($_ENV['SESSION_SECURE'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ],
            'mail' => [
                'driver'      => $_ENV['MAIL_DRIVER'] ?? 'smtp',
                'host'        => $_ENV['MAIL_HOST'] ?? 'localhost',
                'port'        => (int)($_ENV['MAIL_PORT'] ?? 587),
                'username'    => $_ENV['MAIL_USERNAME'] ?? '',
                'password'    => $_ENV['MAIL_PASSWORD'] ?? '',
                'encryption'  => $_ENV['MAIL_ENCRYPTION'] ?? 'tls',
                'from_address'=> $_ENV['MAIL_FROM_ADDRESS'] ?? 'noreply@tierphysio.local',
                'from_name'   => $_ENV['MAIL_FROM_NAME'] ?? 'Tierphysio Manager',
            ],
            'storage' => [
                'path'     => $this->rootPath . '/storage',
                'max_size' => (int)($_ENV['UPLOAD_MAX_SIZE'] ?? 10485760),
            ],
        ];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function set(string $key, mixed $value): void
    {
        $keys = explode('.', $key);
        $config = &$this->config;

        foreach ($keys as $i => $segment) {
            if ($i === count($keys) - 1) {
                $config[$segment] = $value;
            } else {
                if (!isset($config[$segment]) || !is_array($config[$segment])) {
                    $config[$segment] = [];
                }
                $config = &$config[$segment];
            }
        }
    }

    public function all(): array
    {
        return $this->config;
    }
}

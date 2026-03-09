<?php

declare(strict_types=1);

namespace Saas\Core;

class Config
{
    private array $data = [];

    public function __construct(private string $rootPath)
    {
        $this->load();
    }

    private function load(): void
    {
        $this->data = [
            'app' => [
                'name'      => $_ENV['APP_NAME']      ?? 'Tierphysio SaaS',
                'env'       => $_ENV['APP_ENV']       ?? 'production',
                'debug'     => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'url'       => $_ENV['APP_URL']       ?? '',
                'key'       => $_ENV['APP_KEY']       ?? '',
                'locale'    => $_ENV['APP_LOCALE']    ?? 'de',
                'installed' => filter_var($_ENV['INSTALLED'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ],
            'db' => [
                'host'     => $_ENV['DB_HOST']     ?? 'localhost',
                'port'     => (int)($_ENV['DB_PORT'] ?? 3306),
                'database' => $_ENV['DB_DATABASE'] ?? '',
                'username' => $_ENV['DB_USERNAME'] ?? '',
                'password' => $_ENV['DB_PASSWORD'] ?? '',
            ],
            'tenant_db' => [
                'host'          => $_ENV['TENANT_DB_HOST']     ?? $_ENV['DB_HOST']     ?? 'localhost',
                'port'          => (int)($_ENV['TENANT_DB_PORT'] ?? $_ENV['DB_PORT'] ?? 3306),
                'username'      => $_ENV['TENANT_DB_USERNAME'] ?? $_ENV['DB_USERNAME'] ?? '',
                'password'      => $_ENV['TENANT_DB_PASSWORD'] ?? $_ENV['DB_PASSWORD'] ?? '',
                'prefix'        => $_ENV['TENANT_DB_PREFIX']   ?? 'tierphysio_tenant_',
                'shared_hosting'=> filter_var($_ENV['TENANT_DB_SHARED_HOSTING'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ],
            'practice' => [
                'path' => $_ENV['PRACTICE_SOFTWARE_PATH'] ?? '',
                'url'  => $_ENV['PRACTICE_SOFTWARE_URL']  ?? '',
            ],
            'session' => [
                'lifetime' => (int)($_ENV['SESSION_LIFETIME'] ?? 120),
                'secure'   => filter_var($_ENV['SESSION_SECURE'] ?? true, FILTER_VALIDATE_BOOLEAN),
            ],
            'mail' => [
                'driver'    => $_ENV['MAIL_DRIVER']       ?? 'smtp',
                'host'      => $_ENV['MAIL_HOST']         ?? 'localhost',
                'port'      => (int)($_ENV['MAIL_PORT']   ?? 587),
                'username'  => $_ENV['MAIL_USERNAME']     ?? '',
                'password'  => $_ENV['MAIL_PASSWORD']     ?? '',
                'encryption'=> $_ENV['MAIL_ENCRYPTION']   ?? 'tls',
                'from'      => [
                    'address' => $_ENV['MAIL_FROM_ADDRESS'] ?? '',
                    'name'    => $_ENV['MAIL_FROM_NAME']    ?? '',
                ],
            ],
            'license' => [
                'secret' => $_ENV['LICENSE_SECRET'] ?? '',
            ],
        ];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $parts = explode('.', $key);
        $data  = $this->data;

        foreach ($parts as $part) {
            if (!is_array($data) || !array_key_exists($part, $data)) {
                return $default;
            }
            $data = $data[$part];
        }

        return $data;
    }

    public function getRootPath(): string
    {
        return $this->rootPath;
    }
}

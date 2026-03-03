<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SettingsRepository;

class SettingsService
{
    public function __construct(
        private readonly SettingsRepository $settingsRepository
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->settingsRepository->get($key, $default);
    }

    public function set(string $key, string $value): void
    {
        $this->settingsRepository->set($key, $value);
    }

    public function all(): array
    {
        return $this->settingsRepository->all();
    }
}

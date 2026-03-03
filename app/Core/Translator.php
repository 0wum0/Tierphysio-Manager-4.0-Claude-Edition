<?php

declare(strict_types=1);

namespace App\Core;

class Translator
{
    private string $locale;
    private string $langPath;
    private array $translations = [];
    private array $fallback = [];

    public function __construct(string $locale, string $langPath)
    {
        $this->locale   = $locale;
        $this->langPath = $langPath;
        $this->loadTranslations($locale);
        if ($locale !== 'de') {
            $this->loadFallback('de');
        }
    }

    private function loadTranslations(string $locale): void
    {
        $file = $this->langPath . '/' . $locale . '.php';
        if (file_exists($file)) {
            $this->translations = require $file;
        }
    }

    private function loadFallback(string $locale): void
    {
        $file = $this->langPath . '/' . $locale . '.php';
        if (file_exists($file)) {
            $this->fallback = require $file;
        }
    }

    public function trans(string $key, array $replace = []): string
    {
        $value = $this->getFromArray($this->translations, $key)
            ?? $this->getFromArray($this->fallback, $key)
            ?? $key;

        foreach ($replace as $search => $replacement) {
            $value = str_replace(':' . $search, (string)$replacement, $value);
        }

        return $value;
    }

    private function getFromArray(array $array, string $key): ?string
    {
        $keys  = explode('.', $key);
        $value = $array;

        foreach ($keys as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return is_string($value) ? $value : null;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): void
    {
        $this->locale       = $locale;
        $this->translations = [];
        $this->loadTranslations($locale);
    }
}

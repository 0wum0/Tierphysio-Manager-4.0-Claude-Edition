<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Config;

/**
 * Resolves the current tenant from the subdomain and sets the
 * correct table prefix on the Database instance.
 *
 * How it works:
 *  - Request comes in for e.g. mustermann.tp.makeit.uno
 *  - We extract the subdomain "mustermann"
 *  - We look it up in the shared DB (tenants table — same DB as Praxissoftware)
 *  - We get the table_prefix e.g. "tpm3_"
 *  - We call $db->setPrefix("tpm3_") so all queries use the right tables
 *
 * No extra SAAS_DB_* credentials needed — SaaS and Praxissoftware share one DB.
 * If TABLE_PREFIX is set in .env, that is used directly (single-tenant fallback).
 */
class TenantResolver
{
    private string $resolvedPrefix = '';
    private string $resolvedSlug   = '';

    public function __construct(
        private Config   $config,
        private Database $db
    ) {}

    /**
     * Main entry point. Call once in Application::bootstrap().
     */
    public function resolve(): void
    {
        // 1. If TABLE_PREFIX is explicitly set in .env, use it and skip subdomain resolution
        $envPrefix = $_ENV['TABLE_PREFIX'] ?? '';
        if ($envPrefix !== '') {
            $this->resolvedPrefix = $envPrefix;
            $this->db->setPrefix($envPrefix);
            return;
        }

        // 2. Try to resolve from subdomain
        $slug = $this->extractSubdomainSlug();
        if ($slug === '' || $slug === 'www') {
            // No subdomain — single root installation, no prefix
            return;
        }

        // 3. Look up in SaaS DB
        $prefix = $this->lookupPrefixForSlug($slug);
        if ($prefix === null) {
            // Unknown tenant — could show error, or use empty prefix
            // For safety we keep no prefix (will fail gracefully in controllers)
            return;
        }

        $this->resolvedPrefix = $prefix;
        $this->resolvedSlug   = $slug;
        $this->db->setPrefix($prefix);

        // Store in session so it persists across requests
        if (!headers_sent()) {
            $_SESSION['_tenant_slug']   = $slug;
            $_SESSION['_tenant_prefix'] = $prefix;
        }
    }

    public function getPrefix(): string
    {
        return $this->resolvedPrefix;
    }

    public function getSlug(): string
    {
        return $this->resolvedSlug;
    }

    /**
     * Extracts the leftmost subdomain part from the HOST header.
     * e.g. "mustermann.tp.makeit.uno" → "mustermann"
     *      "tp.makeit.uno"            → ""
     *      "localhost"                → ""
     */
    private function extractSubdomainSlug(): string
    {
        $host    = strtolower($_SERVER['HTTP_HOST'] ?? '');
        $appHost = strtolower(parse_url($this->config->get('app.url', ''), PHP_URL_HOST) ?? '');

        if ($appHost === '' || $host === $appHost) {
            return '';
        }

        // Remove port if present
        $host = explode(':', $host)[0];

        // If host ends with appHost, everything before is the subdomain
        if (str_ends_with($host, '.' . $appHost)) {
            $sub = substr($host, 0, strlen($host) - strlen('.' . $appHost));
            // Only single-level subdomains allowed (no dots in slug)
            if (!str_contains($sub, '.')) {
                return $sub;
            }
        }

        // Fallback: first segment of host
        $parts = explode('.', $host);
        if (count($parts) > 2) {
            return $parts[0];
        }

        return '';
    }

    /**
     * Looks up the table prefix for a tenant slug.
     * Uses the existing DB connection — no extra credentials needed
     * since SaaS and Praxissoftware share the same database.
     */
    private function lookupPrefixForSlug(string $slug): ?string
    {
        try {
            $row = $this->db->fetch(
                "SELECT table_prefix FROM tenants
                 WHERE subdomain = ? AND status = 'active'
                 LIMIT 1",
                [$slug]
            );

            if ($row && !empty($row['table_prefix'])) {
                return $row['table_prefix'];
            }

            return null;
        } catch (\Throwable) {
            return null;
        }
    }
}

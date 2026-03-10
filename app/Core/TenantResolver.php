<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Config;

/**
 * Resolves the current tenant from the URL path and sets the
 * correct table prefix on the Database instance.
 *
 * How it works (path-based routing):
 *  - Request comes in for e.g. tp.makeit.uno/tpm6/dashboard
 *  - We extract the first path segment "tpm6"
 *  - We look it up in the shared DB (tenants table)
 *  - We get the table_prefix e.g. "tpm6_"
 *  - We call $db->setPrefix("tpm6_") so all queries use the right tables
 *  - We store the slug so the Router can strip it from the URI
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
        // 1. If TABLE_PREFIX is explicitly set in .env, use it directly
        $envPrefix = $_ENV['TABLE_PREFIX'] ?? '';
        if ($envPrefix !== '') {
            $this->resolvedPrefix = $envPrefix;
            $this->db->setPrefix($envPrefix);
            return;
        }

        // 2. Extract tenant slug from first URL path segment
        $slug = $this->extractPathSlug();
        if ($slug === '') {
            return;
        }

        // 3. Look up in SaaS DB
        $prefix = $this->lookupPrefixForSlug($slug);
        if ($prefix === null) {
            return;
        }

        $this->resolvedPrefix = $prefix;
        $this->resolvedSlug   = $slug;
        $this->db->setPrefix($prefix);

        // Store in session so Controller can build correct redirect paths
        $_SESSION['_tenant_slug']   = $slug;
        $_SESSION['_tenant_prefix'] = $prefix;
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
     * Extracts the first path segment as the tenant slug.
     * e.g. "/tpm6/dashboard" → "tpm6"
     *      "/login"          → "" (not a known tenant slug pattern)
     *      "/"               → ""
     *
     * Only matches slugs that look like a tenant prefix: letters+digits only,
     * starting with a letter, 2–32 chars.
     */
    private function extractPathSlug(): string
    {
        $uri   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        $parts = explode('/', trim($uri, '/'));
        $first = $parts[0] ?? '';

        // Must match tenant slug pattern (e.g. tpm6, tpm12, muster)
        if ($first !== '' && preg_match('/^[a-z][a-z0-9]{1,31}$/i', $first)) {
            return strtolower($first);
        }

        return '';
    }

    /**
     * Looks up the table prefix for a tenant slug (subdomain column = slug).
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

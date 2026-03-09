<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Resolves the current tenant from the subdomain and sets the
 * correct table prefix on the Database instance.
 *
 * How it works:
 *  - Request comes in for e.g. mustermann.tp.makeit.uno
 *  - We extract the subdomain "mustermann"
 *  - We look it up in the SaaS DB (tenants table, using saas_ DB credentials)
 *  - We get the table_prefix e.g. "tpm3_"
 *  - We call $db->setPrefix("tpm3_") so all queries use the right tables
 *
 * If no subdomain / base domain / env TABLE_PREFIX is set, we use
 * whatever TABLE_PREFIX is in the .env (fallback for single-tenant installs).
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
     * Connects to the SaaS DB and looks up the table prefix for a tenant slug.
     * Returns null if not found.
     */
    private function lookupPrefixForSlug(string $slug): ?string
    {
        // SaaS DB credentials (may differ from tenant DB)
        $saasHost = $_ENV['SAAS_DB_HOST']     ?? $_ENV['DB_HOST']     ?? 'localhost';
        $saasPort = (int)($_ENV['SAAS_DB_PORT']     ?? $_ENV['DB_PORT']     ?? 3306);
        $saasDb   = $_ENV['SAAS_DB_DATABASE'] ?? $_ENV['DB_DATABASE'] ?? '';
        $saasUser = $_ENV['SAAS_DB_USERNAME'] ?? $_ENV['DB_USERNAME'] ?? '';
        $saasPass = $_ENV['SAAS_DB_PASSWORD'] ?? $_ENV['DB_PASSWORD'] ?? '';

        if ($saasDb === '') return null;

        try {
            $pdo = new PDO(
                "mysql:host={$saasHost};port={$saasPort};dbname={$saasDb};charset=utf8mb4",
                $saasUser,
                $saasPass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );

            // Look for tenant where uuid or a "subdomain" column matches the slug
            // We use uuid prefix convention: tenant with table_prefix "tpm3_" has slug "tpm3"
            // OR we store slug explicitly in tenants table
            // Primary lookup: by subdomain column
            $stmt = $pdo->prepare(
                "SELECT table_prefix FROM tenants
                 WHERE subdomain = ? AND status = 'active'
                 LIMIT 1"
            );
            $stmt->execute([$slug]);
            $row = $stmt->fetch();

            if ($row && !empty($row['table_prefix'])) {
                return $row['table_prefix'];
            }

            return null;
        } catch (\Throwable) {
            return null;
        }
    }
}

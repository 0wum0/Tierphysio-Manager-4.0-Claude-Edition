<?php

declare(strict_types=1);

namespace Saas\Services;

use PDO;
use Saas\Core\Config;
use Saas\Core\Database;
use Saas\Repositories\TenantRepository;
use Saas\Repositories\SubscriptionRepository;
use Saas\Repositories\PlanRepository;
use Ramsey\Uuid\Uuid;

class TenantProvisioningService
{
    public function __construct(
        private Config                 $config,
        private Database               $db,
        private TenantRepository       $tenantRepo,
        private SubscriptionRepository $subRepo,
        private PlanRepository         $planRepo,
        private LicenseService         $licenseService,
        private MailService            $mailService
    ) {}

    /**
     * Full provisioning: create tenant record → DB → admin user → subscription → license token → welcome mail
     */
    public function provision(array $data): array
    {
        $plan = $this->planRepo->findBySlug($data['plan_slug'] ?? 'basic');
        if (!$plan) {
            throw new \RuntimeException('Ungültiger Abo-Plan');
        }

        return $this->db->transaction(function (Database $db) use ($data, $plan): array {

            // 1. Create tenant record
            $uuid    = Uuid::uuid4()->toString();
            $dbName  = $this->config->get('tenant_db.prefix') . preg_replace('/[^a-z0-9_]/', '_', strtolower($data['email']));
            $dbName  = substr($dbName, 0, 64);

            $tenantId = $this->tenantRepo->create([
                'uuid'          => $uuid,
                'practice_name' => $data['practice_name'],
                'owner_name'    => $data['owner_name'],
                'email'         => $data['email'],
                'phone'         => $data['phone'] ?? null,
                'address'       => $data['address'] ?? null,
                'city'          => $data['city'] ?? null,
                'zip'           => $data['zip'] ?? null,
                'country'       => $data['country'] ?? 'DE',
                'plan_id'       => (int)$plan['id'],
                'status'        => 'pending',
                'trial_ends_at' => null,
            ]);

            // 2. Create tenant database
            $tempPassword = bin2hex(random_bytes(12));
            $dbResult = $this->createTenantDatabase($dbName, $uuid);

            $this->tenantRepo->setDbCreated($tenantId, $dbName);

            // 3. Create admin user in tenant DB
            $adminPassword = $data['admin_password'] ?? bin2hex(random_bytes(8));
            $this->createTenantAdmin(
                $dbName,
                $data['practice_name'],
                $data['owner_name'],
                $data['email'],
                $adminPassword
            );
            $this->tenantRepo->setAdminCreated($tenantId);

            // 4. Activate tenant
            $this->tenantRepo->setStatus($tenantId, 'active');

            // 5. Create subscription
            $billingCycle = $data['billing_cycle'] ?? 'monthly';
            $amount       = $billingCycle === 'yearly' ? $plan['price_year'] : $plan['price_month'];
            $startedAt    = date('Y-m-d H:i:s');
            $endsAt       = $billingCycle === 'yearly'
                            ? date('Y-m-d H:i:s', strtotime('+1 year'))
                            : date('Y-m-d H:i:s', strtotime('+1 month'));

            $this->subRepo->create([
                'tenant_id'      => $tenantId,
                'plan_id'        => (int)$plan['id'],
                'billing_cycle'  => $billingCycle,
                'status'         => 'active',
                'started_at'     => $startedAt,
                'ends_at'        => $endsAt,
                'next_billing'   => $endsAt,
                'amount'         => $amount,
                'currency'       => 'EUR',
                'payment_method' => $data['payment_method'] ?? null,
                'external_id'    => $data['payment_external_id'] ?? null,
            ]);

            // 6. Issue license token
            $licenseToken = $this->licenseService->issueToken($tenantId);

            // 7. Send welcome email
            try {
                $this->mailService->sendWelcome(
                    $data['email'],
                    $data['owner_name'],
                    $data['practice_name'],
                    $data['email'],
                    $adminPassword,
                    $licenseToken
                );
            } catch (\Throwable) {
                // Mail failure should not block provisioning
            }

            return [
                'tenant_id'      => $tenantId,
                'tenant_uuid'    => $uuid,
                'db_name'        => $dbName,
                'admin_email'    => $data['email'],
                'admin_password' => $adminPassword,
                'license_token'  => $licenseToken,
                'plan'           => $plan['slug'],
            ];
        });
    }

    /**
     * Create a fresh tenant database with the Tierphysio schema.
     *
     * On shared hosting (TENANT_DB_SHARED_HOSTING=true) the database must be
     * pre-created in cPanel. We skip CREATE DATABASE and connect directly.
     */
    private function createTenantDatabase(string $dbName, string $tenantUuid): void
    {
        $host       = $this->config->get('tenant_db.host');
        $port       = (int)$this->config->get('tenant_db.port', 3306);
        $username   = $this->config->get('tenant_db.username');
        $password   = $this->config->get('tenant_db.password');
        $shared     = (bool)$this->config->get('tenant_db.shared_hosting', false);

        $pdoOptions = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        if ($shared) {
            // Shared hosting: DB must already exist, connect directly
            $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4";
            $pdo = new PDO($dsn, $username, $password, $pdoOptions);
        } else {
            // Dedicated/VPS: create DB automatically
            $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
            $pdo = new PDO($dsn, $username, $password, $pdoOptions);
            $safe = '`' . str_replace('`', '``', $dbName) . '`';
            $pdo->exec("CREATE DATABASE IF NOT EXISTS {$safe} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE {$safe}");
        }

        // Run Tierphysio schema
        $schemaPath = $this->config->getRootPath() . '/provisioning/tenant_schema.sql';
        if (file_exists($schemaPath)) {
            $sql = file_get_contents($schemaPath);
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                if ($stmt !== '') {
                    try {
                        $pdo->exec($stmt);
                    } catch (\PDOException $e) {
                        // Skip "table already exists" errors on re-provisioning
                        if ($e->getCode() !== '42S01') {
                            throw $e;
                        }
                    }
                }
            }
        }

        // Write tenant identity
        $pdo->exec("INSERT IGNORE INTO settings (`key`, `value`) VALUES ('tenant_uuid', " . $pdo->quote($tenantUuid) . ")");
    }

    /**
     * Create the initial admin user in the tenant database.
     */
    private function createTenantAdmin(
        string $dbName,
        string $practiceName,
        string $ownerName,
        string $email,
        string $password
    ): void {
        $host     = $this->config->get('tenant_db.host');
        $port     = (int)$this->config->get('tenant_db.port');
        $username = $this->config->get('tenant_db.username');
        $passwd   = $this->config->get('tenant_db.password');

        $safe = '`' . str_replace('`', '``', $dbName) . '`';
        $dsn  = "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4";
        $pdo  = new PDO($dsn, $username, $passwd, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $hash  = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $parts = explode(' ', trim($ownerName), 2);
        $first = $parts[0];
        $last  = $parts[1] ?? '';

        $stmt = $pdo->prepare(
            "INSERT INTO users (first_name, last_name, email, password, role, is_active, created_at)
             VALUES (?, ?, ?, ?, 'admin', 1, NOW())"
        );
        $stmt->execute([$first, $last, $email, $hash]);

        // Set practice name in settings
        $pdo->prepare("INSERT INTO settings (`key`, `value`) VALUES ('company_name', ?) ON DUPLICATE KEY UPDATE `value` = ?")
            ->execute([$practiceName, $practiceName]);
    }

    /**
     * Deprovision: revoke license, optionally drop DB.
     */
    public function suspend(int $tenantId): void
    {
        $this->tenantRepo->setStatus($tenantId, 'suspended');
        $this->licenseService->revokeAllTokens($tenantId);
    }

    public function reactivate(int $tenantId): void
    {
        $this->tenantRepo->setStatus($tenantId, 'active');
        $this->licenseService->issueToken($tenantId);
    }

    public function cancel(int $tenantId): void
    {
        $this->tenantRepo->setStatus($tenantId, 'cancelled');
        $this->licenseService->revokeAllTokens($tenantId);
    }
}

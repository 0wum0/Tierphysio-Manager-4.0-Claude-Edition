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

            // 1. Create tenant record (no DB/prefix yet)
            $uuid = Uuid::uuid4()->toString();

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

            // 2. Generate unique table prefix: e.g. tpm1_, tpm2_, ...
            $base        = $this->config->get('tenant_db.prefix_base', 'tpm');
            $tablePrefix = $base . $tenantId . '_';

            // 3. Create tenant tables in shared DB with prefix
            $this->createTenantTables($tablePrefix, $uuid);
            $this->tenantRepo->setTablePrefix($tenantId, $tablePrefix);

            // 4. Create admin user in tenant tables
            $adminPassword = $data['admin_password'] ?? bin2hex(random_bytes(8));
            $this->createTenantAdmin(
                $tablePrefix,
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
                'table_prefix'   => $tablePrefix,
                'admin_email'    => $data['email'],
                'admin_password' => $adminPassword,
                'license_token'  => $licenseToken,
                'plan'           => $plan['slug'],
            ];
        });
    }

    /**
     * Create all tenant tables in the shared database using a unique table prefix.
     * The schema SQL uses {{PREFIX}} as a placeholder which is replaced at runtime.
     */
    private function createTenantTables(string $tablePrefix, string $tenantUuid): void
    {
        $schemaPath = $this->config->getRootPath() . '/provisioning/tenant_schema.sql';
        if (!file_exists($schemaPath)) {
            throw new \RuntimeException('tenant_schema.sql nicht gefunden: ' . $schemaPath);
        }

        $sql = file_get_contents($schemaPath);
        $sql = str_replace('{{PREFIX}}', $tablePrefix, $sql);

        $pdo = $this->getTenantPdo();

        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            if ($stmt !== '') {
                try {
                    $pdo->exec($stmt);
                } catch (\PDOException $e) {
                    // Skip "table already exists" on re-provisioning
                    if ($e->getCode() !== '42S01') {
                        throw $e;
                    }
                }
            }
        }

        // Write tenant identity into the prefix'd settings table
        $pdo->exec(
            "INSERT IGNORE INTO `{$tablePrefix}settings` (`key`, `value`) "
            . "VALUES ('tenant_uuid', " . $pdo->quote($tenantUuid) . ")"
        );
    }

    /**
     * Create the initial admin user in the tenant's prefixed tables.
     */
    private function createTenantAdmin(
        string $tablePrefix,
        string $practiceName,
        string $ownerName,
        string $email,
        string $password
    ): void {
        $pdo  = $this->getTenantPdo();
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        $pdo->prepare(
            "INSERT INTO `{$tablePrefix}users`
             (name, email, password, role, active, created_at)
             VALUES (?, ?, ?, 'admin', 1, NOW())"
        )->execute([$ownerName, $email, $hash]);

        $pdo->prepare(
            "INSERT INTO `{$tablePrefix}settings` (`key`, `value`)
             VALUES ('company_name', ?)
             ON DUPLICATE KEY UPDATE `value` = ?"
        )->execute([$practiceName, $practiceName]);
    }

    /**
     * Returns a PDO connection to the shared tenant database.
     */
    private function getTenantPdo(): PDO
    {
        $host     = $this->config->get('tenant_db.host');
        $port     = (int)$this->config->get('tenant_db.port', 3306);
        $dbName   = $this->config->get('tenant_db.database');
        $username = $this->config->get('tenant_db.username');
        $password = $this->config->get('tenant_db.password');

        return new PDO(
            "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4",
            $username,
            $password,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
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

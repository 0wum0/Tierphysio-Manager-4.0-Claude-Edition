<?php
/**
 * Migration: 004_demo_tenant (PHP)
 * Creates demo tenant info@demo.de / admin123456 with all plugins granted.
 * Demo data expires after 24 hours.
 */
return static function (\Saas\Core\Database $db): void {

    // 1. Demo Plan
    $db->execute(
        "INSERT IGNORE INTO `plans`
           (`slug`,`name`,`description`,`price_month`,`price_year`,`max_users`,`features`,`is_active`,`sort_order`)
         VALUES (?,?,?,?,?,?,?,?,?)",
        [
            'demo',
            'Demo',
            'Kostenlose Demo mit vollem Funktionsumfang (24 Stunden)',
            0.00, 0.00, -1,
            '["invoices","appointments","patients","owners","dashboard","waitlist","intake","staff","reports","premium","marketplace"]',
            1, 99,
        ]
    );

    $planId = $db->fetchColumn("SELECT id FROM plans WHERE slug = 'demo'");

    // 2. Demo Tenant
    $db->execute(
        "INSERT IGNORE INTO `tenants`
           (`uuid`,`practice_name`,`owner_name`,`email`,`phone`,`address`,`city`,`zip`,`country`,
            `plan_id`,`status`,`table_prefix`,`db_created`,`admin_created`,`notes`)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
        [
            'demo-0000-0000-0000-000000000001',
            'Demo-Praxis Tierphysio',
            'Demo Nutzer',
            'info@demo.de',
            '+49 800 0000000',
            'Musterstraße 1',
            'Musterstadt',
            '12345',
            'DE',
            (int)$planId,
            'active',
            'demo_',
            1, 1,
            'Demo-Konto — wird nach 24 Stunden automatisch gelöscht.',
        ]
    );

    // 3. Demo Admin user — password: admin123456
    $hash = password_hash('admin123456', PASSWORD_BCRYPT, ['cost' => 10]);
    $db->execute(
        "INSERT IGNORE INTO `saas_admins` (`name`,`email`,`password`,`role`) VALUES (?,?,?,?)",
        ['Demo Admin', 'info@demo.de', $hash, 'admin']
    );

    // 4. Set is_demo flag + expiry (columns added by 005_demo_schema)
    // Try to update; if columns don't exist yet, silently skip (005 runs after 004)
    try {
        $db->execute(
            "UPDATE `tenants` SET `is_demo`=1, `demo_expires_at`=DATE_ADD(NOW(), INTERVAL 24 HOUR)
             WHERE email='info@demo.de'"
        );
    } catch (\Throwable) {}

    // 5. Grant all active marketplace plugins to demo tenant
    $tenantId = (int)$db->fetchColumn("SELECT id FROM tenants WHERE email='info@demo.de'");
    $plugins  = $db->fetchAll("SELECT id FROM marketplace_plugins WHERE is_active=1");

    foreach ($plugins as $plugin) {
        try {
            $db->execute(
                "INSERT IGNORE INTO `marketplace_purchases`
                   (`tenant_id`,`plugin_id`,`status`,`payment_method`,`payment_ref`,`amount_paid`,`plugin_enabled`,`expires_at`)
                 VALUES (?,?,'active','manual','demo-grant',0.00,1,DATE_ADD(NOW(), INTERVAL 24 HOUR))",
                [$tenantId, (int)$plugin['id']]
            );
        } catch (\Throwable) {}
    }
};

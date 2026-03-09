-- Migration: 007_tenant_provisioning_columns
-- Adds missing provisioning columns to tenants table
-- (table_prefix, db_created, admin_created, subdomain)
-- Safe to run multiple times - uses ALTER TABLE with individual statements

ALTER TABLE `tenants` ADD COLUMN `table_prefix`  VARCHAR(30)  NULL AFTER `country`;
ALTER TABLE `tenants` ADD COLUMN `db_created`    TINYINT(1) NOT NULL DEFAULT 0 AFTER `table_prefix`;
ALTER TABLE `tenants` ADD COLUMN `admin_created` TINYINT(1) NOT NULL DEFAULT 0 AFTER `db_created`;

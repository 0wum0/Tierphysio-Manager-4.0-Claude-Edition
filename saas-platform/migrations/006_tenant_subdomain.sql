-- Migration: 006_tenant_subdomain
-- Adds subdomain column to tenants table
-- Used by TenantResolver to map e.g. mustermann.tp.makeit.uno → tpm3_ prefix

ALTER TABLE `tenants` ADD COLUMN `subdomain` VARCHAR(100) NULL UNIQUE AFTER `uuid`;

-- Backfill: derive subdomain from table_prefix (strip trailing underscore)
-- e.g. table_prefix "tpm3_" → subdomain "tpm3"
UPDATE `tenants`
SET `subdomain` = TRIM(TRAILING '_' FROM `table_prefix`)
WHERE `table_prefix` IS NOT NULL AND `table_prefix` != '' AND `subdomain` IS NULL;

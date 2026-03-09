-- Migration: 006_tenant_subdomain
-- Adds subdomain column to tenants table
-- Used by TenantResolver to map e.g. mustermann.tp.makeit.uno → tpm3_ prefix

ALTER TABLE `tenants` ADD COLUMN `subdomain` VARCHAR(100) NULL UNIQUE AFTER `uuid`;

-- Migration: 005_demo_schema
-- Adds is_demo and demo_expires_at columns to tenants table
-- These statements are run individually; errors for duplicate columns are silently ignored by the runner

ALTER TABLE `tenants` ADD COLUMN `is_demo` TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE `tenants` ADD COLUMN `demo_expires_at` DATETIME NULL;

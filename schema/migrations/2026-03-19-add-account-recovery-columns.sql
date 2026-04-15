-- Migration: Add account recovery columns to users table
-- Run once during deployment (e.g. via migration runner or manually).

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS totp_secret VARCHAR(32) NULL,
    ADD COLUMN IF NOT EXISTS security_question VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS security_answer VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS recovery_codes TEXT NULL;

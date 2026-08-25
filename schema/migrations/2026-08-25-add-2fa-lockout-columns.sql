-- Migration: Add 2FA lockout columns to users table
-- Run once during deployment (e.g. via migration runner or manually).
-- Required by public/verify_otp.php (2FA brute-force lockout).

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS locked_until DATETIME NULL;

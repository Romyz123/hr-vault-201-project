-- Employee statuses are configurable and include values beyond the legacy ENUM.
-- Run once during deployment with a restricted migration account.
ALTER TABLE `employees`
    MODIFY COLUMN `status` VARCHAR(50) NULL DEFAULT 'Active';

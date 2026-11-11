-- Run once during deployment. Existing groups remain unassigned until an administrator
-- selects their correct parent section in Manage Organization Hierarchy.
ALTER TABLE `groups`
    ADD COLUMN `section_id` INT(11) NULL AFTER `name`,
    ADD INDEX `idx_groups_section_id` (`section_id`),
    ADD CONSTRAINT `groups_ibfk_section`
        FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE SET NULL;

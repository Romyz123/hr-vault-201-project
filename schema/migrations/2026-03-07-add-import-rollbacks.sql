-- Migration: Add import_rollbacks table for employee import undo
-- Created: 2026-03-07

CREATE TABLE IF NOT EXISTS import_rollbacks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_id INT NOT NULL,
  import_batch VARCHAR(50) NOT NULL,
  old_data LONGTEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_import_rollbacks_employee (employee_id),
  INDEX idx_import_rollbacks_batch (import_batch)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

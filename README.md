# TESP HR 201 System - Deployment Guide

## 1. System Overview

The **TESP HR 201 System** is a secure, web-based Human Resource Information System (HRIS) designed for managing employee records, digital 201 files, recruitment, and performance reviews.

### Key Features

- **Security:** AES-256 encryption for sensitive documents, Role-Based Access Control (RBAC), and Audit Logging.
- **Compliance:** Designed to meet MHI Security Application Requirements.
- **Modules:** Employee Management, Recruitment & ATS, Disciplinary Console, Performance Reviews, Document Expiry Tracking.

---

## 2. Server Requirements

Ensure the target server meets the following specifications:

- **OS:** Windows Server (IIS/Apache) or Linux (Ubuntu/CentOS).
- **Web Server:** Apache 2.4+ (with `mod_rewrite` enabled).
- **PHP:** Version 8.0 or higher.
  - Extensions: `pdo_mysql`, `openssl`, `mbstring`, `gd`, `zip`, `fileinfo`.
- **Database:** MySQL 5.7+ or MariaDB 10.4+.
- **SSL:** A valid SSL Certificate is **mandatory** for production (HTTPS).

---

## 3. Installation Steps

### Step 1: File Deployment

1.  Copy the source code to the web root (e.g., `/var/www/html/hr201` or `C:\inetpub\wwwroot\hr201`).
2.  **Important:** Ensure the `vault/` directory is created. Ideally, this should be outside the public web root for maximum security, but the system defaults to `../vault/` relative to the `public/` folder.

### Step 2: Database Setup

1.  Create a new empty database (e.g., `hr201_prod`).
2.  Create a database user with full privileges on this database.

### Step 3: Configuration

1.  Navigate to the `config/` directory from your project root.
2.  Create a new file named `config.php` (you can copy the structure below).
3.  **CRITICAL:** Generate a strong, random 32-character string for the `VAULT_KEY`. If this key is lost, encrypted files cannot be recovered.

**`config/config.php` Template:**

```php
<?php
return [
    'DB_HOST' => 'localhost',
    'DB_PORT' => 3306,      // Default MySQL port (Use 3307 for XAMPP if needed)
    'DB_NAME' => 'hr201_prod',
    'DB_USER' => 'hr_app_user',
    'DB_PASS' => 'Strong_DB_Password_Here',
    'DB_CHARSET' => 'utf8mb4',
    'DB_SSL_CA' => null,    // Path to SSL CA cert if using Azure/AWS MySQL

    // Security Keys
    'VAULT_PATH' => __DIR__ . '/../vault/',
    'VAULT_KEY'  => getenv('VAULT_KEY')
];
```

### Step 4: File Permissions

Ensure the web server user (e.g., `www-data`, `apache`, or `IUSR`) has **Write** access to:

- `vault/` (For encrypted documents)
- `public/uploads/` (For avatars and temporary files)
- `backups/` (For generated backup archives)

For the default XAMPP setup, start MySQL on port `3306` with database `hr201_local`, user `root`, and an empty password. The application reads database, vault, backup, and upload settings from protected environment variables. Copy `config/config.example.php` as a deployment reference and set `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, `VAULT_KEY`, `VAULT_PATH`, `BACKUP_PATH`, and `MAX_UPLOAD_BYTES` in Apache or the deployment service.

Never rotate `VAULT_KEY` by replacing it alone. Follow `migrations/README.md` to re-encrypt existing vault files during a maintenance window.

- `backups/` (For system backups)

### Step 5: Database Initialization

1.  Open your browser and navigate to the system URL (e.g., `http://localhost/hr-vault/public/`).
2.  Log in with the default Admin credentials (if provided) or manually insert an admin user into the `users` table if this is a fresh install.
3.  Navigate to the Database Status page (`public/db_status.php`).
4.  Click **"Auto-Fix All Issues"**. This will automatically create all necessary tables and columns based on the schema definition.

---

## 4. Security Hardening (Production Checklist)

1.  **Disable Debugging:**
    - Verify `config/db.php` has `display_errors` set to `0`.
2.  **Clean Up:**
    - Log in as Admin.
    - On the Dashboard, look for the red "Production Security Alert".
    - Click **"Delete All"** to remove development scripts (e.g., `test_vault.php`, `auth_login.php`).
3.  **Web Server Config:**
    - Ensure the `public/.htaccess` file is active to force HTTPS and block directory listing.
    - Configure the document root to point to the `public/` folder, not the project root, to prevent access to `config/` and `src/`.

---

## 5. Maintenance & Backups

### Automated Backups

The system has a built-in backup tool located at `public/cron_backup.php`.

**Windows Task Scheduler Setup:**

- **Trigger:** Weekly (e.g., Fridays at 5:00 PM).
- **Action:** Start a Program.
- **Program/Script:** `php.exe`
- **Arguments:** `C:\path\to\hr201\public\cron_backup.php`

### Manual Backups

Admins can download a full SQL dump and encrypted Vault ZIP from **Manage Users > Disaster Recovery**.

### Logs

- **Activity Logs:** Viewable in the Admin Dashboard (`public/activity_logs.php`).
- **Error Logs:** Check standard PHP error logs on the server for system issues.

---

## 6. Troubleshooting

**"Database connection error"**

- Check `config/config.php` credentials.
- Verify the database port (3306 vs 3307).

**"Decryption Failed"**

- Ensure the `VAULT_KEY` in `config.php` matches the key used to encrypt the files. **Do not change this key** once files are uploaded.

**"Upload Failed"**

- Check folder permissions for `vault/` and `public/uploads/`.
- Check `upload_max_filesize` and `post_max_size` in `php.ini`.

---

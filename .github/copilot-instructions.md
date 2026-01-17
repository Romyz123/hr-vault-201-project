# AI Coding Agent Instructions: HR 201 Secure Document Vault

## Project Overview
HR 201 is a PHP-based secure document management system built on XAMPP with MySQL. It enforces a **two-stage approval workflow**: users upload documents to a pending staging table, then admins approve them into a permanent documents table. The system prioritizes security across file uploads, database access, and user sessions.

## Architecture & Data Flows

### Core Components
- **[public/](public/)** - Web entry points handling user interactions, file uploads, and viewing
- **[config/](config/)** - Database connection (`db.php`) and configuration (`config.php`); uses PHP config file instead of `.env` for XAMPP compatibility
- **[src/Security.php](src/Security.php)** - Centralized security utilities for CSRF, rate limiting, and input sanitization
- **[vault/](vault/)** - Protected directory storing actual PDF files with randomized UUID names

### Critical Data Flow: Document Upload
1. **Form Submission** ([public/upload_form.php](public/upload_form.php)): User selects employee, category, and PDF; CSRF token embedded
2. **Validation** ([public/process_upload.php](public/process_upload.php)): 
   - CSRF check via `$security->checkCSRF()`
   - Strict MIME type validation (PDF only) using `finfo` functions
   - File size limit: 128MB server-side enforcement
   - Secure naming: UUID-based filenames (not user-supplied)
3. **Storage**: Files move to `VAULT_PATH` with UUID naming; metadata stored in `pending_requests` table (not `documents` yet)
4. **Approval**: Admin approves via backend process, migrating from pending → documents table

### Document Retrieval
- [public/view_doc.php](public/view_doc.php) fetches from database using UUID ID
- **Security Critical**: Validates UUID format with strict regex (`/^[a-zA-Z0-9-]+$/`)
- Uses `realpath()` to prevent directory traversal attacks
- Streams PDF inline with proper headers

## Security Patterns (Read/Apply Religiously)

### CSRF Protection
- Every form includes hidden `csrf_token` from `$security->generateCSRF()`
- POST requests validated with `$security->checkCSRF($_POST['csrf_token'])`
- Token stored in `$_SESSION['csrf_token']` (regenerated per session)

### Input Sanitization
- Combine `trim()` + `htmlspecialchars()` for user inputs (see `Security::sanitizeInput()`)
- Use parametrized queries exclusively: `$pdo->prepare()` + `execute([...])`
- Never trust file extensions; validate MIME types with `finfo`

### Rate Limiting
- Call `$security->checkRateLimit($_SERVER['REMOTE_ADDR'])` on sensitive endpoints
- Enforces 60 requests per 60 seconds per IP
- Tracks attempts in `rate_limits` table; returns HTTP 429 if exceeded

### File Security
- **Force PDF-only MIME**: `$allowedMime = ['application/pdf']` 
- **Randomize filenames**: `$uuid = bin2hex(random_bytes(16))`
- **Cleanup on failure**: Unlink uploaded file if database insert fails (prevent orphans)
- **Path validation**: Use `realpath()` + verify file within vault before serving

## Project Conventions

### Configuration
- Environment variables stored in [.env/.env](config/.env) (loaded by [config/config.php](config/config.php))
- Access config via `$_ENV['KEY']` after require-ing `config.php`
- No `.gitignore` patterns for `.env`; assume credentials are local-only for development

### Database Access
- [config/db.php](config/db.php) initializes PDO with `ERRMODE_EXCEPTION` and `FETCH_ASSOC` modes
- PDO options enforce prepared statements (disable `EMULATE_PREPARES`)
- Connection errors logged; users see generic message to prevent info leakage
- Table naming: `pending_requests` (staging) vs `documents` (approved)

### Status & Redirect Patterns
- Upload success: `header("Location: upload_form.php?status=success")`
- Upload errors: `header("Location: upload_form.php?status=error&msg=...")` with error details in query param
- Check status in form: `<?php if (isset($_GET['status'])): ?>`

### HTML Output
- User-controlled strings: Always wrap in `htmlspecialchars()` (see [public/upload_form.php](public/upload_form.php) line 35, 42)
- Bootstrap 5.3 CDN for styling; custom color-coding for status (green, yellow, red, blue)

## Development Workflow

### Setup
1. Configure XAMPP MySQL; ensure default root user or update [config/config.php](config/config.php)
2. Create `hr201_local` database
3. Create `vault/` directory with write permissions (should exist but verify)
4. Update `.env` credentials if needed
5. No build step; PHP files served directly via Apache

### Testing File Uploads
- Use [public/upload_form.php](public/upload_form.php) to submit test PDFs
- Watch [vault/](vault/) directory for UUID-named files
- Check `pending_requests` table for staging records
- Manually test approval flow (admins move pending → documents)

### Debugging Security Issues
- Enable error logging in [config/db.php](config/db.php): monitor PDOException messages
- Check `rate_limits` table if requests return 429
- Verify `csrf_token` is present in `$_SESSION` before form submission
- Test with `curl` to simulate malformed requests (missing CSRF, invalid file types)

## Common AI Agent Tasks

### Adding Features
1. **New document category**: Update dropdown in [public/upload_form.php](public/upload_form.php) + ensure database schema supports it
2. **New validation rule**: Add logic to [public/process_upload.php](public/process_upload.php) before file movement; cleanup file on error
3. **Rate limiting tweak**: Modify limits in `Security::checkRateLimit()` parameters (default: 60 requests/60 seconds)

### Database Modifications
- Always use `$pdo->prepare()` + named/positional parameters
- Test with sample data in local `hr201_local` database
- Backup `.env` credentials before schema changes

### Security Audits
- Verify all `$_POST`/`$_GET` inputs pass through `htmlspecialchars()` or are bound to queries
- Check file upload endpoints use `finfo` MIME validation + randomized naming
- Confirm `realpath()` + vault directory check present in file retrieval endpoints

<?php
// src/FileService.php

class FileService
{
    private $vaultPath;
    private $manifestFile;
    // [SECURITY] Encryption Key (In production, move this to config.php or .env)
    private $key;
    private $legacyKeys = [];
    private $cipher = 'aes-256-gcm';

    public function __construct($vaultPath)
    {
        // Ensure path ends with slash
        $this->vaultPath = rtrim($vaultPath, '/\\') . DIRECTORY_SEPARATOR;

        // [FIX] Auto-create vault directory if it doesn't exist to prevent silent failures
        if (!is_dir($this->vaultPath)) {
            if (!mkdir($this->vaultPath, 0700, true) && !is_dir($this->vaultPath)) {
                throw new Exception("Failed to create vault directory: " . $this->vaultPath);
            }
        }
        if (!is_writable($this->vaultPath)) {
            throw new Exception("Vault directory is not writable.");
        }

        // [SECURITY] Protect vault from direct web access if within web root
        $htaccess = $this->vaultPath . '.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "Deny from all");
        }

        // The Fail-Safe Map (Text file)
        $this->manifestFile = $this->vaultPath . 'manifest_DO_NOT_DELETE.txt';

        // Load the installation secret from protected configuration.
        $config = require __DIR__ . '/../config/config.php';

        $primaryKey = $config['VAULT_KEY'] ?? null;
        if (!is_string($primaryKey) || trim($primaryKey) === '') {
            throw new Exception("CRITICAL SECURITY ERROR: VAULT_KEY is missing in config.php. System halted to protect data.");
        }

        $this->key = trim($primaryKey);
        $legacyKey = $config['LEGACY_VAULT_KEY'] ?? null;
        if (is_string($legacyKey) && trim($legacyKey) !== '') {
            $this->legacyKeys = [trim($legacyKey)];
        }
    }

    private function encrypt($data)
    {
        $ivlen = openssl_cipher_iv_length($this->cipher);
        $iv = random_bytes($ivlen);
        $tag = '';
        $ciphertext = openssl_encrypt($data, $this->cipher, hash('sha256', $this->key, true), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) return false;
        return base64_encode("GCM1" . $iv . $tag . $ciphertext);
    }

    public function decrypt($data)
    {
        $data = base64_decode($data, true);
        if ($data === false) return false;

        $keys = array_merge([$this->key], $this->legacyKeys);
        $keys = array_values(array_unique(array_filter($keys, fn($key) => is_string($key) && trim($key) !== '')));

        foreach ($keys as $keyString) {
            $key = hash('sha256', $keyString, true);
            $ivlen = openssl_cipher_iv_length($this->cipher);
            if (strncmp($data, 'GCM1', 4) === 0) {
                if (strlen($data) < 4 + $ivlen + 16) continue;
                $iv = substr($data, 4, $ivlen);
                $tag = substr($data, 4 + $ivlen, 16);
                $ciphertext = substr($data, 4 + $ivlen + 16);
                $result = openssl_decrypt($ciphertext, $this->cipher, $key, OPENSSL_RAW_DATA, $iv, $tag);
                if ($result !== false) {
                    return $result;
                }
                continue;
            }

            // Read pre-GCM vault entries during migration; all new entries are authenticated.
            $legacyIvlen = openssl_cipher_iv_length('aes-256-cbc');
            if (strlen($data) < $legacyIvlen) continue;
            $result = openssl_decrypt(substr($data, $legacyIvlen), 'aes-256-cbc', $keyString, OPENSSL_RAW_DATA, substr($data, 0, $legacyIvlen));
            if ($result !== false) {
                return $result;
            }
        }

        return false;
    }

    /**
     * Securely saves a file with a random name
     * Returns the RANDOM name (to store in DB 'file_path')
     */
    public function saveFile($tempPath, $originalName)
    {
        // 1. Get Extension safely (e.g. 'pdf')
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        // Validate extension contains only alphanumeric characters
        if (!preg_match('/^[a-z0-9]{1,10}$/', $ext)) {
            $ext = 'bin'; // Safe fallback
        }

        // [SECURITY] Generate a proper UUID v4 string for the filename. 
        // Never use original filename components for storage to prevent injection and disclosure.
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        $uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
        $randomName = $uuid . '.' . $ext;

        // 3. Define Full Target Path
        $targetPath = $this->vaultPath . $randomName;
        // 4. Encrypt & Save
        $content = file_get_contents($tempPath);
        if ($content === false) return false;

        $encryptedContent = $this->encrypt($content);
        if ($encryptedContent === false) return false;

        if (file_put_contents($targetPath, $encryptedContent) !== false) {
            // 5. Write to Fail-Safe Manifest
            $this->logToManifest($randomName, $originalName);

            // Cleanup temp file (since we didn't use move_uploaded_file)
            if (file_exists($tempPath)) @unlink($tempPath);

            return $randomName;
        }

        return false;
    }

    public function getFileContent($filename)
    {
        if (!is_string($filename) || trim($filename) === '') {
            return false;
        }

        $candidates = [];
        $normalized = trim($filename);

        if (preg_match('/^[a-f0-9-]{36}\.[a-z0-9]{1,10}$/i', $normalized)) {
            $candidates[] = $this->vaultPath . $normalized;
        } else {
            $candidates[] = $this->vaultPath . basename($normalized);
            $candidates[] = $this->vaultPath . ltrim($normalized, '/\\');
            $candidates[] = $normalized;
        }

        $seen = [];
        foreach ($candidates as $path) {
            $path = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path);
            if ($path === '' || isset($seen[$path])) continue;
            $seen[$path] = true;

            if (!file_exists($path) || is_dir($path)) {
                continue;
            }

            $content = file_get_contents($path);
            if ($content === false) {
                continue;
            }

            // Support both encrypted vault files and legacy plaintext files.
            $decoded = $this->decrypt($content);
            return $decoded !== false ? $decoded : $content;
        }

        return false;
    }

    private function logToManifest($storedName, $realName)
    {
        // Sanitize input to prevent log injection
        $realName = preg_replace('/[\r\n\x00]/', '', $realName);
        $storedName = preg_replace('/[\r\n\x00]/', '', $storedName);

        // Format: [DATE] STORED_NAME | REAL_NAME
        $entry = sprintf("[%s] STORED: %s | REAL: %s" . PHP_EOL, date('Y-m-d H:i:s'), $storedName, $realName);

        // Encrypt the log entry
        $encryptedEntry = $this->encrypt($entry) . PHP_EOL;
        file_put_contents($this->manifestFile, $encryptedEntry, FILE_APPEND | LOCK_EX);
    }

    public function readManifest()
    {
        if (!file_exists($this->manifestFile)) return [];
        $lines = file($this->manifestFile);
        $decryptedLines = [];

        foreach ($lines as $line) {
            $decrypted = $this->decrypt($line);
            // Strict Mode: Skip lines that fail decryption
            if ($decrypted !== false) {
                $decryptedLines[] = $decrypted;
            }
        }
        return $decryptedLines;
    }
}

<?php
// src/FileService.php

class FileService
{
    private $vaultPath;
    private $manifestFile;
    // [SECURITY] Encryption Key (In production, move this to config.php or .env)
    private $key = 'hr201_vault_secure_key_change_me_immediately';
    private $cipher = 'aes-256-cbc';

    public function __construct($vaultPath)
    {
        // Ensure path ends with slash
        $this->vaultPath = rtrim($vaultPath, '/\\') . DIRECTORY_SEPARATOR;

        // The Fail-Safe Map (Text file)
        $this->manifestFile = $this->vaultPath . 'manifest_DO_NOT_DELETE.txt';
    }

    private function encrypt($data)
    {
        $ivlen = openssl_cipher_iv_length($this->cipher);
        $iv = openssl_random_pseudo_bytes($ivlen);
        // [FIX] Use OPENSSL_RAW_DATA for cleaner binary handling
        $ciphertext = openssl_encrypt($data, $this->cipher, $this->key, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) return false;
        return base64_encode($iv . $ciphertext);
    }

    public function decrypt($data)
    {
        $data = base64_decode($data);
        $ivlen = openssl_cipher_iv_length($this->cipher);
        if (strlen($data) < $ivlen) return false;
        $iv = substr($data, 0, $ivlen);
        $ciphertext = substr($data, $ivlen);
        // [FIX] Use OPENSSL_RAW_DATA to match encrypt
        return openssl_decrypt($ciphertext, $this->cipher, $this->key, OPENSSL_RAW_DATA, $iv);
    }

    /**
     * Securely saves a file with a random name
     * Returns the RANDOM name (to store in DB 'file_path')
     */
    public function saveFile($tempPath, $originalName)
    {
        // 1. Get Extension safely (e.g. 'pdf')
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        // 2. Generate Random Filename (e.g. 'a8f9-b2c3.pdf')
        // We keep the extension so you know it's a PDF if DB fails
        $randomName = bin2hex(random_bytes(8)) . '.' . $ext;

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
        $path = $this->vaultPath . $filename;
        if (!file_exists($path)) return false;

        $content = file_get_contents($path);
        $decrypted = $this->decrypt($content);

        // Fallback: If decryption fails, it might be an old unencrypted file
        return ($decrypted !== false) ? $decrypted : $content;
    }

    private function logToManifest($storedName, $realName)
    {
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
            $line = trim($line);
            if (empty($line)) continue;

            $decrypted = $this->decrypt($line);
            // Fallback for old unencrypted lines
            $decryptedLines[] = ($decrypted !== false) ? $decrypted : $line;
        }
        return $decryptedLines;
    }
}

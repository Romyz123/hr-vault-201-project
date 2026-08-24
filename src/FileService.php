<?php
// src/FileService.php

class FileService
{
    private $vaultPath;
    private $manifestFile;
    private $key;
    private $cipher = 'aes-256-gcm';
    private $legacyCipher = 'aes-256-cbc';

    public function __construct($vaultPath, $key = null)
    {
        $this->vaultPath = rtrim($vaultPath, '/\\') . DIRECTORY_SEPARATOR;
        $this->manifestFile = $this->vaultPath . 'manifest_DO_NOT_DELETE.txt';

        $resolvedKey = $key ?? getenv('VAULT_KEY') ?: ($_ENV['VAULT_KEY'] ?? null);
        if (!$resolvedKey || !is_string($resolvedKey)) {
            $resolvedKey = $_ENV['VAULT_KEY'] ?? 'hr201-dev-local-secret-change-me';
        }
        $this->key = hash('sha256', $resolvedKey, true);
    }

    private function encrypt($data)
    {
        $ivlen = openssl_cipher_iv_length($this->cipher);
        $iv = openssl_random_pseudo_bytes($ivlen);
        $tag = '';
        $ciphertext = openssl_encrypt($data, $this->cipher, $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            return false;
        }
        return base64_encode($iv . $tag . $ciphertext);
    }

    public function decrypt($data)
    {
        if (!is_string($data) || $data === '') {
            return false;
        }

        $decoded = base64_decode($data, true);
        if ($decoded === false) {
            return false;
        }

        $ivlen = openssl_cipher_iv_length($this->cipher);
        if (strlen($decoded) >= $ivlen + 16) {
            $iv = substr($decoded, 0, $ivlen);
            $tag = substr($decoded, $ivlen, 16);
            $ciphertext = substr($decoded, $ivlen + 16);
            $plain = openssl_decrypt($ciphertext, $this->cipher, $this->key, OPENSSL_RAW_DATA, $iv, $tag);
            if ($plain !== false) {
                return $plain;
            }
        }

        $legacyIvLen = openssl_cipher_iv_length($this->legacyCipher);
        if (strlen($decoded) >= $legacyIvLen) {
            $legacyIv = substr($decoded, 0, $legacyIvLen);
            $legacyCipherText = substr($decoded, $legacyIvLen);
            $legacyPlain = openssl_decrypt($legacyCipherText, $this->legacyCipher, $this->key, OPENSSL_RAW_DATA, $legacyIv);
            if ($legacyPlain !== false) {
                return $legacyPlain;
            }
        }

        return false;
    }

    public function saveFile($tempPath, $originalName)
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $randomName = bin2hex(random_bytes(8)) . '.' . $ext;
        $targetPath = $this->vaultPath . $randomName;
        $content = file_get_contents($tempPath);
        if ($content === false) {
            return false;
        }

        $encryptedContent = $this->encrypt($content);
        if ($encryptedContent === false) {
            return false;
        }

        if (file_put_contents($targetPath, $encryptedContent) !== false) {
            $this->logToManifest($randomName, $originalName);
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
            return $randomName;
        }

        return false;
    }

    public function getFileContent($filename)
    {
        $path = $this->vaultPath . $filename;
        if (!file_exists($path)) {
            return false;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return false;
        }

        $decrypted = $this->decrypt($content);
        return ($decrypted !== false) ? $decrypted : $content;
    }

    private function logToManifest($storedName, $realName)
    {
        $entry = sprintf("[%s] STORED: %s | REAL: %s" . PHP_EOL, date('Y-m-d H:i:s'), $storedName, $realName);
        $encryptedEntry = $this->encrypt($entry) . PHP_EOL;
        file_put_contents($this->manifestFile, $encryptedEntry, FILE_APPEND | LOCK_EX);
    }

    public function readManifest()
    {
        if (!file_exists($this->manifestFile)) {
            return [];
        }

        $lines = file($this->manifestFile);
        $decryptedLines = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decrypted = $this->decrypt($line);
            $decryptedLines[] = ($decrypted !== false) ? $decrypted : $line;
        }
        return $decryptedLines;
    }
}

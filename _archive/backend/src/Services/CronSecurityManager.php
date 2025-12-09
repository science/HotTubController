<?php

declare(strict_types=1);

namespace HotTubController\Services;

use RuntimeException;
use InvalidArgumentException;

/**
 * CronSecurityManager - Manages API keys and security for cron operations
 *
 * This service handles the generation, storage, and rotation of API keys
 * used by cron jobs to authenticate with the heating control system.
 * It ensures secure storage and provides utilities for key management.
 */
class CronSecurityManager
{
    private const API_KEY_FILE = 'storage/cron-api-key.txt';
    private const API_KEY_PREFIX = 'cron_api_';
    private const KEY_LENGTH = 64; // 64 hex characters = 256 bits
    private const BACKUP_SUFFIX = '.backup';

    private string $projectRoot;
    private string $apiKeyFile;
    private string $logFile;

    public function __construct(?string $projectRoot = null)
    {
        $this->projectRoot = $projectRoot ?? dirname(__DIR__, 2);
        $this->apiKeyFile = $this->projectRoot . '/' . self::API_KEY_FILE;
        $this->logFile = $this->projectRoot . '/storage/logs/cron-security.log';

        $this->ensureDirectoriesExist();
    }

    /**
     * Generate and store a new cron API key
     *
     * @param bool $backupExisting Whether to backup existing key before replacing
     * @return string The generated API key
     * @throws RuntimeException If key generation or storage fails
     */
    public function generateApiKey(bool $backupExisting = true): string
    {
        $this->log('Generating new cron API key');

        // Backup existing key if requested and exists
        if ($backupExisting && file_exists($this->apiKeyFile)) {
            $this->backupExistingKey();
        }

        // Generate cryptographically secure random key
        $randomBytes = random_bytes(self::KEY_LENGTH / 2); // 32 bytes = 64 hex chars
        $apiKey = self::API_KEY_PREFIX . bin2hex($randomBytes);

        // Store key with restrictive permissions
        $this->storeApiKey($apiKey);

        $this->log("Generated new cron API key with ID: " . substr($apiKey, 0, 20) . "...");

        return $apiKey;
    }

    /**
     * Get the current API key
     *
     * @return string The current API key
     * @throws RuntimeException If key file doesn't exist or is unreadable
     */
    public function getCurrentApiKey(): string
    {
        if (!$this->apiKeyExists()) {
            throw new RuntimeException("Cron API key file not found: {$this->apiKeyFile}");
        }

        $apiKey = trim(file_get_contents($this->apiKeyFile));

        if (empty($apiKey)) {
            throw new RuntimeException("Cron API key file is empty: {$this->apiKeyFile}");
        }

        if (!$this->isValidApiKey($apiKey)) {
            throw new RuntimeException("Invalid API key format in file: {$this->apiKeyFile}");
        }

        return $apiKey;
    }

    /**
     * Check if API key file exists
     *
     * @return bool True if API key file exists and is readable
     */
    public function apiKeyExists(): bool
    {
        return file_exists($this->apiKeyFile) && is_readable($this->apiKeyFile);
    }

    /**
     * Validate that an API key matches the expected format
     *
     * @param string $apiKey The API key to validate
     * @return bool True if the key is valid
     */
    public function isValidApiKey(string $apiKey): bool
    {
        // Check prefix
        if (!str_starts_with($apiKey, self::API_KEY_PREFIX)) {
            return false;
        }

        // Check total length (prefix + hex chars)
        $expectedLength = strlen(self::API_KEY_PREFIX) + self::KEY_LENGTH;
        if (strlen($apiKey) !== $expectedLength) {
            return false;
        }

        // Check that the suffix is valid hex
        $hexPart = substr($apiKey, strlen(self::API_KEY_PREFIX));
        return ctype_xdigit($hexPart);
    }

    /**
     * Rotate the API key (generate new key and backup old one)
     *
     * @return array Contains 'old_key' and 'new_key'
     * @throws RuntimeException If rotation fails
     */
    public function rotateApiKey(): array
    {
        $this->log('Starting API key rotation');

        $oldKey = null;
        if ($this->apiKeyExists()) {
            try {
                $oldKey = $this->getCurrentApiKey();
            } catch (RuntimeException $e) {
                $this->log("Warning: Could not read old API key during rotation: " . $e->getMessage());
            }
        }

        $newKey = $this->generateApiKey(true);

        $this->log('API key rotation completed successfully');

        return [
            'old_key' => $oldKey,
            'new_key' => $newKey,
        ];
    }

    /**
     * Initialize API key system (generate key if it doesn't exist)
     *
     * @return string The API key (existing or newly generated)
     */
    public function initializeApiKey(): string
    {
        if ($this->apiKeyExists()) {
            try {
                $existingKey = $this->getCurrentApiKey();
                $this->log('Using existing cron API key');
                return $existingKey;
            } catch (RuntimeException $e) {
                $this->log("Existing API key invalid, generating new one: " . $e->getMessage());
                return $this->generateApiKey(true);
            }
        }

        $this->log('No existing API key found, generating new one');
        return $this->generateApiKey(false);
    }

    /**
     * Verify that an API key matches the stored key
     *
     * @param string $providedKey The key to verify
     * @return bool True if the key matches
     */
    public function verifyApiKey(string $providedKey): bool
    {
        if (!$this->apiKeyExists()) {
            return false;
        }

        try {
            $storedKey = $this->getCurrentApiKey();
            return hash_equals($storedKey, $providedKey);
        } catch (RuntimeException $e) {
            $this->log("Error verifying API key: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get API key file information
     *
     * @return array Information about the API key file
     */
    public function getApiKeyInfo(): array
    {
        $info = [
            'file_path' => $this->apiKeyFile,
            'exists' => false,
            'readable' => false,
            'writable' => false,
            'size' => 0,
            'modified' => null,
            'permissions' => null,
            'valid_format' => false,
        ];

        if (file_exists($this->apiKeyFile)) {
            $info['exists'] = true;
            $info['readable'] = is_readable($this->apiKeyFile);
            $info['writable'] = is_writable($this->apiKeyFile);
            $info['size'] = filesize($this->apiKeyFile);
            $info['modified'] = date('Y-m-d H:i:s', filemtime($this->apiKeyFile));
            $info['permissions'] = substr(sprintf('%o', fileperms($this->apiKeyFile)), -4);

            if ($info['readable']) {
                try {
                    $apiKey = $this->getCurrentApiKey();
                    $info['valid_format'] = $this->isValidApiKey($apiKey);
                    $info['key_preview'] = substr($apiKey, 0, 20) . '...';
                } catch (RuntimeException $e) {
                    $info['valid_format'] = false;
                    $info['error'] = $e->getMessage();
                }
            }
        }

        return $info;
    }

    /**
     * Clean up backup API key files older than specified days
     *
     * @param int $olderThanDays Remove backups older than this many days
     * @return int Number of backup files removed
     */
    public function cleanupOldBackups(int $olderThanDays = 30): int
    {
        $backupPattern = $this->apiKeyFile . self::BACKUP_SUFFIX . '*';
        $backupFiles = glob($backupPattern);
        $removedCount = 0;

        $cutoffTime = time() - ($olderThanDays * 24 * 60 * 60);

        foreach ($backupFiles as $backupFile) {
            if (filemtime($backupFile) < $cutoffTime) {
                if (unlink($backupFile)) {
                    $this->log("Removed old backup file: " . basename($backupFile));
                    $removedCount++;
                }
            }
        }

        return $removedCount;
    }

    /**
     * Store API key to file with secure permissions
     */
    private function storeApiKey(string $apiKey): void
    {
        // Write to temporary file first, then move (atomic operation)
        $tempFile = $this->apiKeyFile . '.tmp';

        if (file_put_contents($tempFile, $apiKey) === false) {
            throw new RuntimeException("Failed to write API key to temporary file: {$tempFile}");
        }

        // Set restrictive permissions (owner read/write only)
        if (!chmod($tempFile, 0600)) {
            unlink($tempFile);
            throw new RuntimeException("Failed to set permissions on API key file");
        }

        // Atomic move to final location
        if (!rename($tempFile, $this->apiKeyFile)) {
            unlink($tempFile);
            throw new RuntimeException("Failed to move API key file to final location");
        }

        $this->log("API key stored successfully with secure permissions");
    }

    /**
     * Backup existing API key with timestamp
     */
    private function backupExistingKey(): void
    {
        if (!file_exists($this->apiKeyFile)) {
            return;
        }

        $timestamp = date('Y-m-d_H-i-s');
        $backupFile = $this->apiKeyFile . self::BACKUP_SUFFIX . '_' . $timestamp;

        if (!copy($this->apiKeyFile, $backupFile)) {
            throw new RuntimeException("Failed to backup existing API key to: {$backupFile}");
        }

        // Ensure backup has same restrictive permissions
        chmod($backupFile, 0600);

        $this->log("Backed up existing API key to: " . basename($backupFile));
    }

    /**
     * Ensure required directories exist with proper permissions
     */
    private function ensureDirectoriesExist(): void
    {
        $directories = [
            dirname($this->apiKeyFile),
            dirname($this->logFile),
        ];

        foreach ($directories as $dir) {
            if (!is_dir($dir)) {
                if (!mkdir($dir, 0755, true)) {
                    throw new RuntimeException("Failed to create directory: {$dir}");
                }
            }
        }
    }

    /**
     * Log a message to the security log file
     */
    private function log(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $pid = getmypid();
        $logEntry = "[{$timestamp}] [INFO] [{$pid}] {$message}\n";

        file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
}

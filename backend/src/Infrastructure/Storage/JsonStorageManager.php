<?php

declare(strict_types=1);

namespace HotTubController\Infrastructure\Storage;

use HotTubController\Domain\Storage\StorageException;
use DateTime;

class JsonStorageManager
{
    private string $basePath;
    private array $config;
    private array $lockedFiles = [];

    public function __construct(string $basePath, array $config = [])
    {
        $this->basePath = rtrim($basePath, '/');
        $this->config = array_merge($this->getDefaultConfig(), $config);
        
        $this->ensureDirectoryExists($this->basePath);
    }

    public function load(string $key): array
    {
        $filePath = $this->getFilePath($key);
        
        if (!file_exists($filePath)) {
            return [];
        }
        
        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new StorageException("Failed to read file: {$filePath}");
        }
        
        if (empty($content)) {
            return [];
        }
        
        $data = json_decode($content, true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new StorageException("Invalid JSON in file: {$filePath}. Error: " . json_last_error_msg());
        }
        
        return $data ?? [];
    }

    public function save(string $key, array $data): bool
    {
        $filePath = $this->getFilePath($key);
        $this->ensureDirectoryExists(dirname($filePath));
        
        // Check if rotation is needed before saving
        $this->checkRotation($key);
        
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new StorageException("Failed to encode JSON for key: {$key}");
        }
        
        return $this->writeWithLock($filePath, $json);
    }

    public function exists(string $key): bool
    {
        return file_exists($this->getFilePath($key));
    }

    public function delete(string $key): bool
    {
        $filePath = $this->getFilePath($key);
        if (file_exists($filePath)) {
            return unlink($filePath);
        }
        return true;
    }

    public function cleanup(): int
    {
        $deleted = 0;
        $retentionDays = $this->config['rotation']['retention_days'];
        $cutoffDate = new DateTime("-{$retentionDays} days");
        
        foreach ($this->getDataDirectories() as $dir) {
            $files = glob($dir . '/*.json');
            foreach ($files as $file) {
                $fileDate = $this->extractDateFromFilename($file);
                if ($fileDate && $fileDate < $cutoffDate) {
                    if (unlink($file)) {
                        $deleted++;
                    }
                }
            }
            
            // Also clean up compressed files
            $compressedFiles = glob($dir . '/archive/*.json.gz');
            foreach ($compressedFiles as $file) {
                $fileDate = $this->extractDateFromFilename($file);
                if ($fileDate && $fileDate < $cutoffDate) {
                    if (unlink($file)) {
                        $deleted++;
                    }
                }
            }
        }
        
        return $deleted;
    }

    private function getFilePath(string $key): string
    {
        $keyParts = explode('/', $key);
        $filename = array_pop($keyParts);
        
        if ($this->shouldUseRotation($key)) {
            $date = (new DateTime())->format('Y-m-d');
            $filename = $date . '.json';
        } elseif (!str_ends_with($filename, '.json')) {
            $filename .= '.json';
        }
        
        $directory = $this->basePath;
        if (!empty($keyParts)) {
            $directory .= '/' . implode('/', $keyParts);
        }
        
        return $directory . '/' . $filename;
    }

    private function shouldUseRotation(string $key): bool
    {
        // Keys ending with _rotated use daily rotation
        return str_ends_with($key, '_rotated') || 
               in_array($key, ['heating_cycles', 'heating_events_history']);
    }

    private function checkRotation(string $key): void
    {
        if (!$this->shouldUseRotation($key)) {
            return;
        }
        
        $filePath = $this->getFilePath($key);
        
        if (file_exists($filePath)) {
            $shouldRotate = false;
            
            // Check size-based rotation
            if ($this->config['rotation']['strategy'] === 'size' || $this->config['rotation']['strategy'] === 'both') {
                $fileSize = filesize($filePath);
                if ($fileSize !== false && $fileSize >= $this->config['rotation']['max_size']) {
                    $shouldRotate = true;
                }
            }
            
            // Daily rotation happens automatically by filename, no action needed
            
            if ($shouldRotate) {
                $this->rotateFile($filePath);
            }
        }
        
        // Archive old files
        $this->archiveOldFiles($key);
    }

    private function rotateFile(string $filePath): void
    {
        $timestamp = filemtime($filePath);
        if ($timestamp === false) {
            return;
        }
        
        $date = date('Y-m-d-H-i-s', $timestamp);
        $pathInfo = pathinfo($filePath);
        $rotatedPath = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '-' . $date . '.json';
        
        rename($filePath, $rotatedPath);
    }

    private function archiveOldFiles(string $key): void
    {
        if (!function_exists('gzencode')) {
            return; // Compression not available
        }
        
        $compressAfterDays = $this->config['rotation']['compress_after_days'];
        $cutoffDate = new DateTime("-{$compressAfterDays} days");
        
        $keyParts = explode('/', $key);
        array_pop($keyParts); // Remove filename
        $directory = $this->basePath;
        if (!empty($keyParts)) {
            $directory .= '/' . implode('/', $keyParts);
        }
        
        $archiveDir = $directory . '/archive';
        $files = glob($directory . '/*.json');
        
        foreach ($files as $file) {
            if (basename($file) === date('Y-m-d') . '.json') {
                continue; // Don't archive today's file
            }
            
            $fileDate = $this->extractDateFromFilename($file);
            if ($fileDate && $fileDate < $cutoffDate) {
                $this->compressFile($file, $archiveDir);
            }
        }
    }

    private function compressFile(string $filePath, string $archiveDir): void
    {
        $this->ensureDirectoryExists($archiveDir);
        
        $content = file_get_contents($filePath);
        if ($content === false) {
            return;
        }
        
        $compressed = gzencode($content, 9);
        if ($compressed === false) {
            return;
        }
        
        $filename = basename($filePath);
        $archivePath = $archiveDir . '/' . $filename . '.gz';
        
        if (file_put_contents($archivePath, $compressed, LOCK_EX)) {
            unlink($filePath);
        }
    }

    private function extractDateFromFilename(string $filePath): ?DateTime
    {
        $filename = basename($filePath);
        $filename = preg_replace('/\.(json|gz)$/', '', $filename);
        $filename = preg_replace('/\.json$/', '', $filename); // Handle .json.gz
        
        // Try to match YYYY-MM-DD pattern
        if (preg_match('/(\d{4}-\d{2}-\d{2})/', $filename, $matches)) {
            try {
                return new DateTime($matches[1]);
            } catch (\Exception $e) {
                return null;
            }
        }
        
        return null;
    }

    private function writeWithLock(string $filePath, string $content): bool
    {
        if (!$this->config['locking']['enabled']) {
            return file_put_contents($filePath, $content) !== false;
        }
        
        $handle = fopen($filePath, 'c');
        if ($handle === false) {
            throw new StorageException("Failed to open file for writing: {$filePath}");
        }
        
        $this->lockedFiles[$filePath] = $handle;
        
        $timeout = $this->config['locking']['timeout'];
        $retryDelay = $this->config['locking']['retry_delay'];
        $startTime = time();
        
        while (time() - $startTime < $timeout) {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                ftruncate($handle, 0);
                $result = fwrite($handle, $content);
                flock($handle, LOCK_UN);
                fclose($handle);
                unset($this->lockedFiles[$filePath]);
                
                return $result !== false;
            }
            
            usleep($retryDelay);
        }
        
        fclose($handle);
        unset($this->lockedFiles[$filePath]);
        
        throw new StorageException("Failed to acquire lock for file: {$filePath}");
    }

    private function ensureDirectoryExists(string $path): void
    {
        if (!is_dir($path)) {
            if (!mkdir($path, 0755, true)) {
                throw new StorageException("Failed to create directory: {$path}");
            }
        }
    }

    private function getDataDirectories(): array
    {
        $directories = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->basePath),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $file) {
            if ($file->isDir() && !in_array($file->getFilename(), ['.', '..'])) {
                $directories[] = $file->getPathname();
            }
        }
        
        return $directories;
    }

    private function getDefaultConfig(): array
    {
        return [
            'rotation' => [
                'strategy' => 'daily',
                'max_size' => 1048576, // 1MB
                'retention_days' => 7,
                'compress_after_days' => 2,
            ],
            'locking' => [
                'enabled' => true,
                'timeout' => 5,
                'retry_delay' => 100000, // microseconds
            ],
        ];
    }

    public function __destruct()
    {
        // Close any remaining locked files
        foreach ($this->lockedFiles as $handle) {
            if (is_resource($handle)) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    }
}
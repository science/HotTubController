<?php

declare(strict_types=1);

namespace HotTubController\Services;

use DateTime;
use Exception;
use InvalidArgumentException;
use RuntimeException;

/**
 * CronManager - Self-Deleting Cron Management System
 * 
 * This service manages cron jobs for the hot tub controller system using
 * a self-deleting pattern where each cron removes itself after execution.
 * This eliminates orphaned crons and provides clean one-shot behavior.
 */
class CronManager
{
    private const CRON_TAG_START = 'HOT_TUB_START';
    private const CRON_TAG_MONITOR = 'HOT_TUB_MONITOR';
    
    private const WRAPPER_SCRIPT_PATH = 'storage/bin/cron-wrapper.sh';
    private const CONFIG_DIR = 'storage/curl-configs';
    private const LOG_FILE = 'storage/logs/cron-manager.log';
    
    private string $projectRoot;
    private string $wrapperScriptPath;
    private string $configDir;
    
    public function __construct(?string $projectRoot = null)
    {
        $this->projectRoot = $projectRoot ?? dirname(__DIR__, 2);
        $this->wrapperScriptPath = $this->projectRoot . '/' . self::WRAPPER_SCRIPT_PATH;
        $this->configDir = $this->projectRoot . '/' . self::CONFIG_DIR;
        
        $this->ensureDirectoriesExist();
    }
    
    /**
     * Add a self-deleting cron job that executes once at the specified time
     *
     * @param DateTime $executionTime When to execute the cron
     * @param string $curlConfigFile Path to curl config file for the API call
     * @param string $tag Cron tag (START or MONITOR)
     * @param string $identifier Unique identifier for this cron
     * @return string The cron ID that was created
     * @throws InvalidArgumentException If parameters are invalid
     * @throws RuntimeException If cron creation fails
     */
    public function addSelfDeletingCron(
        DateTime $executionTime,
        string $curlConfigFile,
        string $tag,
        string $identifier
    ): string {
        $this->validateTag($tag);
        $this->validateIdentifier($identifier);
        
        if (!file_exists($curlConfigFile)) {
            throw new InvalidArgumentException("Curl config file does not exist: {$curlConfigFile}");
        }
        
        // Generate unique cron ID
        $cronId = "{$tag}:{$identifier}";
        
        // Build cron expression from DateTime
        $cronExpression = $this->buildCronExpression($executionTime);
        
        // Build the full cron command
        $command = $this->buildCronCommand($cronId, $curlConfigFile);
        
        // Create the full cron entry
        $cronEntry = "{$cronExpression} {$command} # {$cronId}";
        
        $this->log("Adding self-deleting cron: {$cronId}");
        
        // Add to crontab
        $this->addToCrontab($cronEntry);
        
        $this->log("Successfully added cron: {$cronId}");
        
        return $cronId;
    }
    
    /**
     * Add a heating start cron that triggers /api/start-heating
     *
     * @param DateTime $startTime When to start heating
     * @param string $eventId Unique event identifier
     * @param string $curlConfigFile Path to curl config file
     * @return string The cron ID that was created
     */
    public function addStartEvent(DateTime $startTime, string $eventId, string $curlConfigFile): string
    {
        return $this->addSelfDeletingCron($startTime, $curlConfigFile, self::CRON_TAG_START, $eventId);
    }
    
    /**
     * Add a temperature monitoring cron that triggers /api/monitor-temp
     *
     * @param DateTime $checkTime When to check temperature
     * @param string $cycleId Active heating cycle identifier
     * @param string $curlConfigFile Path to curl config file
     * @return string The cron ID that was created
     */
    public function addMonitoringEvent(DateTime $checkTime, string $cycleId, string $curlConfigFile): string
    {
        return $this->addSelfDeletingCron($checkTime, $curlConfigFile, self::CRON_TAG_MONITOR, $cycleId);
    }
    
    /**
     * Remove crons by tag pattern (backup cleanup method)
     *
     * @param string $tagPattern Pattern to match (e.g., 'HOT_TUB_START', 'HOT_TUB_MONITOR')
     * @return int Number of crons removed
     */
    public function removeByTag(string $tagPattern): int
    {
        $this->log("Removing crons with tag pattern: {$tagPattern}");
        
        $currentCrontab = $this->getCurrentCrontab();
        $filteredLines = [];
        $removedCount = 0;
        
        foreach ($currentCrontab as $line) {
            if ($this->isCronLine($line) && $this->matchesTagPattern($line, $tagPattern)) {
                $this->log("Removing cron line: " . trim($line));
                $removedCount++;
            } else {
                $filteredLines[] = $line;
            }
        }
        
        if ($removedCount > 0) {
            $this->setCrontab($filteredLines);
            $this->log("Removed {$removedCount} crons with tag pattern: {$tagPattern}");
        }
        
        return $removedCount;
    }
    
    /**
     * Remove all START event crons
     *
     * @param string|null $eventId Optional specific event ID to remove
     * @return int Number of crons removed
     */
    public function removeStartEvents(?string $eventId = null): int
    {
        $pattern = $eventId ? self::CRON_TAG_START . ":{$eventId}" : self::CRON_TAG_START;
        return $this->removeByTag($pattern);
    }
    
    /**
     * Remove all MONITOR event crons
     *
     * @param string|null $cycleId Optional specific cycle ID to remove
     * @return int Number of crons removed
     */
    public function removeMonitoringEvents(?string $cycleId = null): int
    {
        $pattern = $cycleId ? self::CRON_TAG_MONITOR . ":{$cycleId}" : self::CRON_TAG_MONITOR;
        return $this->removeByTag($pattern);
    }
    
    /**
     * Emergency cleanup: remove ALL application crons
     *
     * @return int Number of crons removed
     */
    public function removeAllApplicationCrons(): int
    {
        $this->log("Emergency cleanup: removing all application crons");
        
        $startRemoved = $this->removeByTag(self::CRON_TAG_START);
        $monitorRemoved = $this->removeByTag(self::CRON_TAG_MONITOR);
        
        $totalRemoved = $startRemoved + $monitorRemoved;
        $this->log("Emergency cleanup complete: removed {$totalRemoved} crons");
        
        return $totalRemoved;
    }
    
    /**
     * List all application crons currently in crontab
     *
     * @return array Array of cron information
     */
    public function listApplicationCrons(): array
    {
        $currentCrontab = $this->getCurrentCrontab();
        $applicationCrons = [];
        
        foreach ($currentCrontab as $line) {
            if ($this->isCronLine($line) && $this->isApplicationCron($line)) {
                $cronInfo = $this->parseCronLine($line);
                if ($cronInfo) {
                    $applicationCrons[] = $cronInfo;
                }
            }
        }
        
        return $applicationCrons;
    }
    
    /**
     * List START event crons
     *
     * @return array Array of start event cron information
     */
    public function listStartEvents(): array
    {
        return array_filter($this->listApplicationCrons(), function ($cron) {
            return strpos($cron['tag'], self::CRON_TAG_START) === 0;
        });
    }
    
    /**
     * List MONITOR event crons
     *
     * @return array Array of monitor event cron information
     */
    public function listMonitoringEvents(): array
    {
        return array_filter($this->listApplicationCrons(), function ($cron) {
            return strpos($cron['tag'], self::CRON_TAG_MONITOR) === 0;
        });
    }
    
    /**
     * Clean up orphaned config files (files without corresponding crons)
     *
     * @return int Number of config files cleaned up
     */
    public function cleanupOrphanedConfigFiles(): int
    {
        if (!is_dir($this->configDir)) {
            return 0;
        }
        
        $activeCrons = $this->listApplicationCrons();
        $activeConfigFiles = [];
        
        // Extract config file paths from active crons
        foreach ($activeCrons as $cron) {
            if (preg_match('/curl --config "([^"]+)"/', $cron['command'], $matches)) {
                $activeConfigFiles[] = basename($matches[1]);
            }
        }
        
        $cleanupCount = 0;
        $configFiles = glob($this->configDir . '/*.conf');
        
        foreach ($configFiles as $configFile) {
            $filename = basename($configFile);
            if (!in_array($filename, $activeConfigFiles)) {
                if (unlink($configFile)) {
                    $this->log("Cleaned up orphaned config file: {$filename}");
                    $cleanupCount++;
                }
            }
        }
        
        return $cleanupCount;
    }
    
    /**
     * Build cron expression from DateTime object
     */
    private function buildCronExpression(DateTime $dateTime): string
    {
        return sprintf(
            '%d %d %d %d *',
            (int) $dateTime->format('i'), // minute
            (int) $dateTime->format('H'), // hour
            (int) $dateTime->format('d'), // day
            (int) $dateTime->format('n')  // month
        );
    }
    
    /**
     * Build the full cron command using the wrapper script
     */
    private function buildCronCommand(string $cronId, string $curlConfigFile): string
    {
        return sprintf(
            '%s "%s" "%s" >/dev/null 2>&1',
            escapeshellarg($this->wrapperScriptPath),
            escapeshellarg($cronId),
            escapeshellarg($curlConfigFile)
        );
    }
    
    /**
     * Add a cron entry to the current crontab
     */
    private function addToCrontab(string $cronEntry): void
    {
        $currentCrontab = $this->getCurrentCrontab();
        $currentCrontab[] = $cronEntry;
        $this->setCrontab($currentCrontab);
    }
    
    /**
     * Get current crontab as array of lines
     */
    private function getCurrentCrontab(): array
    {
        $output = [];
        $returnCode = 0;
        
        exec('crontab -l 2>/dev/null', $output, $returnCode);
        
        // If no crontab exists, return empty array
        if ($returnCode !== 0) {
            return [];
        }
        
        return $output;
    }
    
    /**
     * Set the crontab from array of lines
     */
    private function setCrontab(array $lines): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'crontab');
        
        try {
            file_put_contents($tempFile, implode("\n", $lines) . "\n");
            
            $command = "crontab " . escapeshellarg($tempFile);
            $output = [];
            $returnCode = 0;
            
            exec($command, $output, $returnCode);
            
            if ($returnCode !== 0) {
                throw new RuntimeException("Failed to update crontab: " . implode("\n", $output));
            }
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }
    
    /**
     * Check if a line is a cron job (not a comment or empty line)
     */
    private function isCronLine(string $line): bool
    {
        $line = trim($line);
        return !empty($line) && !str_starts_with($line, '#');
    }
    
    /**
     * Check if a cron line belongs to our application
     */
    private function isApplicationCron(string $line): bool
    {
        return strpos($line, '# HOT_TUB_') !== false;
    }
    
    /**
     * Check if a cron line matches a tag pattern
     */
    private function matchesTagPattern(string $line, string $pattern): bool
    {
        return strpos($line, "# {$pattern}") !== false;
    }
    
    /**
     * Parse a cron line and extract information
     */
    private function parseCronLine(string $line): ?array
    {
        $line = trim($line);
        
        // Extract comment (tag)
        if (!preg_match('/# (HOT_TUB_(?:START|MONITOR):[^\\s]+)$/', $line, $matches)) {
            return null;
        }
        
        $tag = $matches[1];
        $commandPart = trim(str_replace("# {$tag}", '', $line));
        
        // Parse cron expression (first 5 fields)
        $parts = preg_split('/\\s+/', $commandPart);
        if (count($parts) < 5) {
            return null;
        }
        
        $cronExpression = implode(' ', array_slice($parts, 0, 5));
        $command = implode(' ', array_slice($parts, 5));
        
        return [
            'tag' => $tag,
            'cron_expression' => $cronExpression,
            'command' => $command,
            'full_line' => $line,
        ];
    }
    
    /**
     * Validate cron tag
     */
    private function validateTag(string $tag): void
    {
        $validTags = [self::CRON_TAG_START, self::CRON_TAG_MONITOR];
        
        if (!in_array($tag, $validTags)) {
            throw new InvalidArgumentException("Invalid cron tag: {$tag}");
        }
    }
    
    /**
     * Validate identifier format
     */
    private function validateIdentifier(string $identifier): void
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $identifier)) {
            throw new InvalidArgumentException("Invalid identifier format: {$identifier}");
        }
        
        if (strlen($identifier) > 50) {
            throw new InvalidArgumentException("Identifier too long (max 50 chars): {$identifier}");
        }
    }
    
    /**
     * Ensure required directories exist with proper permissions
     */
    private function ensureDirectoriesExist(): void
    {
        $directories = [
            dirname($this->wrapperScriptPath),
            $this->configDir,
            dirname($this->projectRoot . '/' . self::LOG_FILE),
        ];
        
        foreach ($directories as $dir) {
            if (!is_dir($dir)) {
                if (!mkdir($dir, 0755, true)) {
                    throw new RuntimeException("Failed to create directory: {$dir}");
                }
            }
        }
        
        // Ensure config directory has restrictive permissions
        chmod($this->configDir, 0700);
    }
    
    /**
     * Log a message to the cron manager log file
     */
    private function log(string $message): void
    {
        $logFile = $this->projectRoot . '/' . self::LOG_FILE;
        $timestamp = date('Y-m-d H:i:s');
        $pid = getmypid();
        $logEntry = "[{$timestamp}] [INFO] [{$pid}] {$message}\n";
        
        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
}
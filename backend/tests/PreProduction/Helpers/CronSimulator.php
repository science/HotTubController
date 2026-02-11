<?php

declare(strict_types=1);

namespace HotTub\Tests\PreProduction\Helpers;

use PHPUnit\Framework\Assert;

/**
 * Simulates cron daemon behavior for E2E tests.
 *
 * This class interacts with the REAL crontab and executes ACTUAL commands,
 * ensuring E2E tests use identical code paths as production.
 */
class CronSimulator
{
    private const HEAT_TARGET_MARKER = 'HOTTUB:heat-target';
    private const HOTTUB_MARKER = 'HOTTUB:';

    /**
     * Get all heat-target cron entries from real crontab.
     *
     * @return string[] Array of full cron entry lines
     */
    public function getHeatTargetEntries(): array
    {
        $crontab = shell_exec('crontab -l 2>/dev/null') ?? '';
        if (empty(trim($crontab))) {
            return [];
        }

        $lines = explode("\n", $crontab);
        return array_values(array_filter(
            $lines,
            fn($line) => str_contains($line, self::HEAT_TARGET_MARKER)
        ));
    }

    /**
     * Extract the executable command from a cron entry.
     *
     * Cron format: "min hour day month dow command # comment"
     * We extract everything between the 5th field and the comment.
     *
     * @param string $cronEntry Full cron line
     * @return string The command portion only
     */
    public function extractCommand(string $cronEntry): string
    {
        // Remove comment (everything after #)
        $withoutComment = preg_replace('/#.*$/', '', $cronEntry);

        // Cron has 5 time fields, then command
        // Format: min hour day month dow command
        // We need to skip the first 5 space-separated fields
        $parts = preg_split('/\s+/', trim($withoutComment), 6);

        if (count($parts) < 6) {
            throw new \InvalidArgumentException("Invalid cron entry format: $cronEntry");
        }

        return trim($parts[5]);
    }

    /**
     * Execute a cron command exactly as cron daemon would.
     *
     * This runs the ACTUAL command from the crontab, not a reconstruction.
     *
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    public function executeCommand(string $command): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $process = proc_open($command, $descriptors, $pipes, null, null);

        if (!is_resource($process)) {
            return [
                'exitCode' => -1,
                'stdout' => '',
                'stderr' => 'Failed to start process',
            ];
        }

        fclose($pipes[0]); // Close stdin

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'exitCode' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    /**
     * Remove a specific cron entry from crontab.
     *
     * This simulates a one-time job being removed after firing.
     */
    public function removeEntry(string $cronEntry): void
    {
        $crontab = shell_exec('crontab -l 2>/dev/null') ?? '';
        if (empty(trim($crontab))) {
            return;
        }

        $lines = explode("\n", $crontab);
        $filtered = array_filter($lines, fn($line) => trim($line) !== trim($cronEntry));

        $newCrontab = implode("\n", $filtered);
        $tempFile = tempnam(sys_get_temp_dir(), 'crontab_');
        file_put_contents($tempFile, $newCrontab . "\n");
        shell_exec("crontab $tempFile 2>/dev/null");
        unlink($tempFile);
    }

    /**
     * Remove ALL heat-target cron entries.
     */
    public function removeAllHeatTargetEntries(): void
    {
        $crontab = shell_exec('crontab -l 2>/dev/null') ?? '';
        if (empty(trim($crontab))) {
            return;
        }

        $lines = explode("\n", $crontab);
        $filtered = array_filter(
            $lines,
            fn($line) => !str_contains($line, self::HEAT_TARGET_MARKER)
        );

        $newCrontab = implode("\n", $filtered);
        $tempFile = tempnam(sys_get_temp_dir(), 'crontab_');
        file_put_contents($tempFile, $newCrontab . "\n");
        shell_exec("crontab $tempFile 2>/dev/null");
        unlink($tempFile);
    }

    /**
     * Parse scheduled time from cron entry.
     *
     * @return int Unix timestamp of when cron would fire
     */
    public function parseScheduledTime(string $cronEntry): int
    {
        // Extract: min hour day month
        if (!preg_match('/^(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+\*/', $cronEntry, $matches)) {
            throw new \InvalidArgumentException("Cannot parse cron time from: $cronEntry");
        }

        $minute = (int) $matches[1];
        $hour = (int) $matches[2];
        $day = (int) $matches[3];
        $month = (int) $matches[4];
        $year = (int) date('Y');

        // Use SYSTEM timezone (where cron daemon runs), NOT PHP's configured timezone.
        // This accurately simulates how cron will interpret the time fields.
        $systemTimezone = \HotTub\Services\TimeConverter::getSystemTimezone();
        $dt = new \DateTime();
        $dt->setTimezone(new \DateTimeZone($systemTimezone));
        $dt->setDate($year, $month, $day);
        $dt->setTime($hour, $minute, 0);

        return $dt->getTimestamp();
    }

    /**
     * Assert cron is scheduled for a valid future time.
     *
     * Validation rules:
     * 1. Scheduled minute must be AFTER current minute
     * 2. At least 5 seconds until scheduled minute boundary
     *
     * @throws \PHPUnit\Framework\AssertionFailedError if validation fails
     */
    public function assertValidScheduleTime(string $cronEntry, string $context = ''): void
    {
        $scheduledTime = $this->parseScheduledTime($cronEntry);
        $now = time();

        $secondsUntilFire = $scheduledTime - $now;
        $currentMinute = (int) floor($now / 60);
        $scheduledMinute = (int) floor($scheduledTime / 60);

        $prefix = $context ? "$context\n" : '';

        // Rule 1: Must be a future minute (not current or past)
        Assert::assertGreaterThan(
            $currentMinute,
            $scheduledMinute,
            $prefix .
            "CRON SCHEDULING BUG: Scheduled for current or past minute!\n" .
            "Current time: " . date('Y-m-d H:i:s', $now) . " (minute $currentMinute)\n" .
            "Scheduled: " . date('Y-m-d H:i:s', $scheduledTime) . " (minute $scheduledMinute)\n" .
            "Cron daemon fires at :00 of each minute. This job may never run!\n" .
            "Entry: $cronEntry"
        );

        // Rule 2: At least 5 seconds until fire time
        Assert::assertGreaterThanOrEqual(
            5,
            $secondsUntilFire,
            $prefix .
            "CRON SCHEDULING BUG: Less than 5 seconds until cron fires!\n" .
            "Current time: " . date('Y-m-d H:i:s', $now) . "\n" .
            "Scheduled: " . date('Y-m-d H:i:s', $scheduledTime) . "\n" .
            "Seconds until fire: $secondsUntilFire\n" .
            "This is too close - crontab write could cause a miss.\n" .
            "Entry: $cronEntry"
        );
    }

    /**
     * Fire the next heat-target cron entry immediately.
     *
     * This is the main simulation method - it:
     * 1. Gets the next cron entry
     * 2. Validates it's scheduled correctly
     * 3. Extracts and executes the actual command
     * 4. Removes the entry (simulating one-time job)
     *
     * @return array{exitCode: int, stdout: string, stderr: string, cronEntry: string}
     */
    public function fireNextHeatTargetCron(): array
    {
        $entries = $this->getHeatTargetEntries();

        if (empty($entries)) {
            throw new \RuntimeException('No heat-target cron entries to fire');
        }

        $entry = $entries[0];

        // Validate scheduling (will throw if invalid)
        $this->assertValidScheduleTime($entry, 'fireNextHeatTargetCron validation');

        // Extract actual command from crontab
        $command = $this->extractCommand($entry);

        // Execute it exactly as cron daemon would
        $result = $this->executeCommand($command);

        // Remove the entry (one-time job simulation)
        // Note: cron-runner.sh removes its own entry, but we do it here too for safety
        $this->removeEntry($entry);

        return array_merge($result, ['cronEntry' => $entry]);
    }

    /**
     * Get ALL HOTTUB cron entries (scheduler + heat-target-check).
     *
     * This includes both SchedulerService entries (HOTTUB:job-xxx, HOTTUB:rec-xxx)
     * and TargetTemperatureService entries (HOTTUB:heat-target-xxx).
     *
     * @return string[] Array of full cron entry lines
     */
    public function getAllHottubEntries(): array
    {
        $crontab = shell_exec('crontab -l 2>/dev/null') ?? '';
        if (empty(trim($crontab))) {
            return [];
        }

        $lines = explode("\n", $crontab);
        return array_values(array_filter(
            $lines,
            fn($line) => str_contains($line, self::HOTTUB_MARKER)
        ));
    }

    /**
     * Find cron entries for a specific job ID.
     *
     * Searches for HOTTUB:{jobId} in crontab comments.
     *
     * @param string $jobId Job ID (e.g., "rec-abc123", "job-abc123", "heat-target-abc123")
     * @return string[] Matching cron entry lines
     */
    public function getEntriesByJobId(string $jobId): array
    {
        $crontab = shell_exec('crontab -l 2>/dev/null') ?? '';
        if (empty(trim($crontab))) {
            return [];
        }

        $lines = explode("\n", $crontab);
        return array_values(array_filter(
            $lines,
            fn($line) => str_contains($line, 'HOTTUB:' . $jobId)
        ));
    }

    /**
     * Fire a cron by job ID - execute command, then check if entry was self-removed.
     *
     * Unlike fireNextHeatTargetCron(), this does NOT remove the entry itself.
     * Instead, it lets cron-runner.sh handle removal (one-off jobs) or preservation
     * (recurring jobs), then checks whether the entry was self-removed.
     *
     * @param string $jobId Job ID to fire
     * @return array{exitCode: int, stdout: string, stderr: string, cronEntry: string, selfRemoved: bool}
     */
    public function fireByJobId(string $jobId): array
    {
        $entries = $this->getEntriesByJobId($jobId);

        if (empty($entries)) {
            throw new \RuntimeException("No cron entry found for job ID: $jobId");
        }

        $entry = $entries[0];

        // Extract and execute the actual command
        $command = $this->extractCommand($entry);
        $result = $this->executeCommand($command);

        // Check if cron-runner.sh self-removed the entry
        $entriesAfter = $this->getEntriesByJobId($jobId);
        $selfRemoved = empty($entriesAfter);

        return array_merge($result, [
            'cronEntry' => $entry,
            'selfRemoved' => $selfRemoved,
        ]);
    }

    /**
     * Remove ALL HOTTUB entries from crontab (broader than removeAllHeatTargetEntries).
     *
     * Removes scheduler entries (HOTTUB:job-xxx, HOTTUB:rec-xxx) AND
     * heat-target-check entries (HOTTUB:heat-target-xxx).
     */
    public function removeAllHottubEntries(): void
    {
        $crontab = shell_exec('crontab -l 2>/dev/null') ?? '';
        if (empty(trim($crontab))) {
            return;
        }

        $lines = explode("\n", $crontab);
        $filtered = array_filter(
            $lines,
            fn($line) => !str_contains($line, self::HOTTUB_MARKER)
        );

        $newCrontab = implode("\n", $filtered);
        $tempFile = tempnam(sys_get_temp_dir(), 'crontab_');
        file_put_contents($tempFile, $newCrontab . "\n");
        shell_exec("crontab $tempFile 2>/dev/null");
        unlink($tempFile);
    }

    /**
     * Extract job ID from a HOTTUB cron entry comment.
     *
     * Handles both formats:
     * - HOTTUB:rec-abc123:TARGET:DAILY
     * - HOTTUB:job-abc123:TARGET:ONCE
     * - HOTTUB:heat-target-abc123:HEAT-TARGET:ONCE
     *
     * @return string|null Job ID or null if not found
     */
    public function extractJobId(string $cronEntry): ?string
    {
        if (preg_match('/HOTTUB:((?:rec|job|heat-target)-[a-f0-9]+)/', $cronEntry, $matches)) {
            return $matches[1];
        }
        return null;
    }
}

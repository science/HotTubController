<?php

declare(strict_types=1);

namespace HotTub\Services;

use HotTub\Contracts\CrontabAdapterInterface;

/**
 * Centralized service for scheduling cron jobs with correct timezone handling.
 *
 * This service is the SINGLE SOURCE OF TRUTH for converting timestamps to cron
 * expressions. It ensures that cron jobs are scheduled in the system timezone
 * (where cron actually runs), NOT PHP's configured timezone.
 *
 * WHY THIS EXISTS:
 * Cron daemon runs in the OS system timezone. PHP often runs in UTC. If you
 * use PHP's timezone to format cron expressions, jobs fire at the wrong time.
 * This service centralizes the timezone conversion to prevent that bug.
 */
class CronSchedulingService
{
    private CrontabAdapterInterface $crontabAdapter;
    private TimeConverter $timeConverter;

    public function __construct(
        CrontabAdapterInterface $crontabAdapter,
        ?TimeConverter $timeConverter = null
    ) {
        $this->crontabAdapter = $crontabAdapter;
        $this->timeConverter = $timeConverter ?? new TimeConverter();
    }

    /**
     * Schedule a one-time cron job.
     *
     * @param int $unixTimestamp When to run (Unix timestamp, timezone-agnostic)
     * @param string $command The command to execute
     * @param string $comment Cron comment for identification (e.g., "HOTTUB:job-123:ON:ONCE")
     * @return string The cron expression that was scheduled (minute hour day month *)
     */
    public function scheduleAt(int $unixTimestamp, string $command, string $comment): string
    {
        $cronExpression = $this->getCronExpression($unixTimestamp, useUtc: false);

        $entry = sprintf(
            '%s %s # %s',
            $cronExpression,
            $command,
            $comment
        );

        $this->crontabAdapter->addEntry($entry);

        return $cronExpression;
    }

    /**
     * Schedule a recurring daily cron job.
     *
     * @param string $timeWithOffset Time in "HH:MM+/-HH:MM" format (e.g., "06:30-08:00" for 6:30 AM Pacific)
     * @param string $command The command to execute
     * @param string $comment Cron comment for identification
     * @return string The cron expression that was scheduled (minute hour * * *)
     */
    public function scheduleDaily(string $timeWithOffset, string $command, string $comment): string
    {
        // Parse the time with offset and convert to system timezone
        $serverDateTime = $this->timeConverter->parseTimeWithOffset($timeWithOffset, toServerTz: true);

        $minute = (int) $serverDateTime->format('i');
        $hour = (int) $serverDateTime->format('G');

        $cronExpression = sprintf('%d %d * * *', $minute, $hour);

        $entry = sprintf(
            '%s %s # %s',
            $cronExpression,
            $command,
            $comment
        );

        $this->crontabAdapter->addEntry($entry);

        return $cronExpression;
    }

    /**
     * Get the cron expression for a timestamp WITHOUT scheduling.
     *
     * Useful for:
     * - Creating health checks (which need UTC cron expressions)
     * - Logging/debugging what cron expression would be generated
     *
     * @param int $unixTimestamp Unix timestamp
     * @param bool $useUtc If true, return UTC expression; if false, return server timezone expression
     * @return string Cron expression (minute hour day month *)
     */
    public function getCronExpression(int $unixTimestamp, bool $useUtc = false): string
    {
        $dateTime = new \DateTime('@' . $unixTimestamp);

        if ($useUtc) {
            $dateTime->setTimezone(new \DateTimeZone('UTC'));
        } else {
            // Use SYSTEM timezone (where cron runs), NOT PHP's date_default_timezone_get()
            $systemTimezone = TimeConverter::getSystemTimezone();
            $dateTime->setTimezone(new \DateTimeZone($systemTimezone));
        }

        return $this->formatCronExpression($dateTime);
    }

    /**
     * Format a DateTime as a cron expression.
     *
     * @param \DateTime $dateTime The datetime to format
     * @return string Cron expression (minute hour day month *)
     */
    private function formatCronExpression(\DateTime $dateTime): string
    {
        return sprintf(
            '%d %d %d %d *',
            (int) $dateTime->format('i'),  // minute (cast removes leading zeros)
            (int) $dateTime->format('G'),  // hour (24-hour, no leading zero)
            (int) $dateTime->format('j'),  // day of month (no leading zero)
            (int) $dateTime->format('n')   // month (no leading zero)
        );
    }
}

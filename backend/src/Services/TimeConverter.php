<?php

declare(strict_types=1);

namespace HotTub\Services;

use DateTime;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Centralized timezone conversion service.
 *
 * This service provides a single code path for all timezone conversions,
 * ensuring both one-off and recurring jobs handle timezones consistently.
 *
 * Architecture:
 * - Input: Times with timezone offset (from client)
 * - Storage: UTC (industry standard)
 * - Cron: Server SYSTEM timezone (cron runs in the OS timezone, NOT PHP's timezone)
 * - API response: UTC (client converts to local for display)
 *
 * IMPORTANT: This service uses the SYSTEM timezone (from /etc/timezone or similar),
 * not PHP's configured timezone (date_default_timezone_get()). This is critical
 * because cron runs in the system timezone, which may differ from PHP's config.
 */
class TimeConverter
{
    private static ?string $systemTimezoneCache = null;

    /**
     * Get the system's timezone (where cron runs).
     *
     * This detects the actual OS timezone, which may differ from PHP's
     * configured timezone (date_default_timezone_get()).
     *
     * Detection order:
     * 1. /etc/timezone file (Debian/Ubuntu)
     * 2. /etc/localtime symlink target (RHEL/CentOS/macOS)
     * 3. TZ environment variable
     * 4. Fallback to PHP's timezone (last resort)
     *
     * @return string IANA timezone identifier (e.g., "America/Los_Angeles")
     */
    public static function getSystemTimezone(): string
    {
        // Use cached value if available
        if (self::$systemTimezoneCache !== null) {
            return self::$systemTimezoneCache;
        }

        // Method 1: /etc/timezone (Debian/Ubuntu)
        if (is_readable('/etc/timezone')) {
            $tz = trim(file_get_contents('/etc/timezone'));
            if ($tz && self::isValidTimezone($tz)) {
                self::$systemTimezoneCache = $tz;
                return $tz;
            }
        }

        // Method 2: /etc/localtime symlink (RHEL/CentOS/macOS)
        if (is_link('/etc/localtime')) {
            $link = readlink('/etc/localtime');
            // Extract timezone from path like /usr/share/zoneinfo/America/Los_Angeles
            if (preg_match('#zoneinfo/(.+)$#', $link, $matches)) {
                $tz = $matches[1];
                if (self::isValidTimezone($tz)) {
                    self::$systemTimezoneCache = $tz;
                    return $tz;
                }
            }
        }

        // Method 3: TZ environment variable
        $envTz = getenv('TZ');
        if ($envTz && self::isValidTimezone($envTz)) {
            self::$systemTimezoneCache = $envTz;
            return $envTz;
        }

        // Fallback: PHP's timezone (not ideal, but better than nothing)
        self::$systemTimezoneCache = date_default_timezone_get();
        return self::$systemTimezoneCache;
    }

    /**
     * Check if a timezone identifier is valid.
     */
    private static function isValidTimezone(string $tz): bool
    {
        try {
            new DateTimeZone($tz);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Clear the cached system timezone (useful for testing).
     */
    public static function clearTimezoneCache(): void
    {
        self::$systemTimezoneCache = null;
    }

    /**
     * Convert a datetime string to server-local timezone.
     *
     * Use this for generating cron expressions, which run in the SYSTEM timezone.
     * Note: This uses the OS timezone, not PHP's configured timezone.
     *
     * @param string $isoTime ISO 8601 datetime string with timezone offset
     * @return DateTime DateTime in server's system timezone
     */
    public function toServerTimezone(string $isoTime): DateTime
    {
        $dateTime = new DateTime($isoTime);
        $dateTime->setTimezone(new DateTimeZone(self::getSystemTimezone()));

        return $dateTime;
    }

    /**
     * Convert a datetime string to UTC.
     *
     * Use this for storing times and API responses.
     *
     * @param string $isoTime ISO 8601 datetime string with timezone offset
     * @return DateTime DateTime in UTC
     */
    public function toUtc(string $isoTime): DateTime
    {
        $dateTime = new DateTime($isoTime);
        $dateTime->setTimezone(new DateTimeZone('UTC'));

        return $dateTime;
    }

    /**
     * Parse a time string with timezone offset (for recurring jobs).
     *
     * Recurring jobs send time as "HH:MM" with offset (e.g., "06:30-08:00").
     * This method parses that format and optionally converts to server or UTC timezone.
     *
     * The date component uses a reference date (2030-01-01) since recurring
     * jobs only care about the time portion.
     *
     * @param string $timeWithOffset Time in "HH:MM+/-HH:MM" format (e.g., "06:30-08:00")
     * @param bool $toServerTz If true, convert result to server timezone
     * @param bool $toUtc If true, convert result to UTC
     * @return DateTime Parsed DateTime
     * @throws InvalidArgumentException If format is invalid
     */
    public function parseTimeWithOffset(string $timeWithOffset, bool $toServerTz = false, bool $toUtc = false): DateTime
    {
        // Parse format: HH:MM+HH:MM or HH:MM-HH:MM
        // Examples: 06:30-08:00, 14:30+00:00, 06:30+05:30
        if (!preg_match('/^(\d{2}):(\d{2})([+-])(\d{2}):(\d{2})$/', $timeWithOffset, $matches)) {
            throw new InvalidArgumentException(
                "Invalid time format: '$timeWithOffset'. Expected HH:MM+HH:MM or HH:MM-HH:MM"
            );
        }

        $hour = $matches[1];
        $minute = $matches[2];
        $sign = $matches[3];
        $offsetHour = $matches[4];
        $offsetMinute = $matches[5];

        // Construct a full ISO 8601 datetime using a reference date
        // We use a fixed reference date because recurring jobs don't have a date component
        $isoTime = sprintf(
            '2030-01-01T%s:%s:00%s%s:%s',
            $hour,
            $minute,
            $sign,
            $offsetHour,
            $offsetMinute
        );

        $dateTime = new DateTime($isoTime);

        if ($toServerTz) {
            $dateTime->setTimezone(new DateTimeZone(self::getSystemTimezone()));
        } elseif ($toUtc) {
            $dateTime->setTimezone(new DateTimeZone('UTC'));
        }

        return $dateTime;
    }

    /**
     * Format a time-with-offset string as UTC time for storage.
     *
     * This is used for recurring jobs: converts "06:30-08:00" to "14:30:00+00:00".
     *
     * @param string $timeWithOffset Time in "HH:MM+/-HH:MM" format
     * @return string Time in "HH:MM:SS+00:00" format (UTC)
     */
    public function formatTimeUtc(string $timeWithOffset): string
    {
        $utcTime = $this->parseTimeWithOffset($timeWithOffset, toUtc: true);

        return $utcTime->format('H:i:s') . '+00:00';
    }
}

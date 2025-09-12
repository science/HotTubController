<?php

declare(strict_types=1);

namespace HotTubController\Support;

/**
 * Centralized Debug Output Utility
 *
 * Provides level-based console output control for the hot tub controller.
 * Production code specifies the debug level of messages, and this utility
 * checks if that level should be output based on current DEBUG_LEVEL setting.
 *
 * Debug Levels:
 * - 0: ERRORS only (test default)
 * - 1: ERRORS + WARNINGS (production default)
 * - 2: ERRORS + WARNINGS + INFO (development default)
 * - 3: ERRORS + WARNINGS + INFO + DEBUG (full verbosity)
 */
class DebugOutput
{
    public const LEVEL_ERROR = 0;   // Always shown (critical errors)
    public const LEVEL_WARN = 1;    // Warnings and above
    public const LEVEL_INFO = 2;    // General information
    public const LEVEL_DEBUG = 3;   // Detailed debug information

    /**
     * Output a message at the specified debug level
     *
     * @param string $message The message to output
     * @param int $level The debug level of this message
     */
    public static function output(string $message, int $level = self::LEVEL_INFO): void
    {
        $currentLevel = self::getCurrentDebugLevel();

        if ($level <= $currentLevel) {
            error_log($message);
        }
    }

    /**
     * Output an error message (always shown)
     */
    public static function error(string $message): void
    {
        self::output($message, self::LEVEL_ERROR);
    }

    /**
     * Output a warning message (shown at level 1+)
     */
    public static function warn(string $message): void
    {
        self::output($message, self::LEVEL_WARN);
    }

    /**
     * Output an info message (shown at level 2+)
     */
    public static function info(string $message): void
    {
        self::output($message, self::LEVEL_INFO);
    }

    /**
     * Output a debug message (shown at level 3+)
     */
    public static function debug(string $message): void
    {
        self::output($message, self::LEVEL_DEBUG);
    }

    /**
     * Get the current debug level from environment
     */
    private static function getCurrentDebugLevel(): int
    {
        $level = $_ENV['DEBUG_LEVEL'] ?? null;

        if ($level === null) {
            // Default based on environment
            $appEnv = $_ENV['APP_ENV'] ?? 'production';

            return match ($appEnv) {
                'testing', 'test' => self::LEVEL_ERROR,    // Silent tests
                'production' => self::LEVEL_WARN,          // Errors + warnings
                'development' => self::LEVEL_INFO,         // Errors + warnings + info
                default => self::LEVEL_WARN
            };
        }

        return max(0, min(3, (int) $level));
    }

    /**
     * Get current debug level (for testing/debugging)
     */
    public static function getDebugLevel(): int
    {
        return self::getCurrentDebugLevel();
    }
}

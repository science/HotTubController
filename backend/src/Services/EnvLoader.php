<?php

declare(strict_types=1);

namespace HotTub\Services;

use RuntimeException;

/**
 * File-based environment configuration loader.
 *
 * Loads configuration from .env files, enabling simple deployment workflows:
 * - FTP/cPanel: Just copy the correct .env file to the server
 * - No dependency on system environment variables
 * - No external dependencies (pure PHP)
 *
 * Supported .env format:
 * - KEY=value (simple values)
 * - KEY="quoted value" or KEY='quoted value'
 * - # comments (ignored)
 * - KEY=value # inline comments (stripped)
 * - Empty lines (ignored)
 */
class EnvLoader
{
    /**
     * Load configuration from a .env file.
     *
     * @param string $path Path to the .env file
     * @return array<string, string> Parsed configuration key-value pairs
     * @throws RuntimeException If the file doesn't exist
     */
    public function load(string $path): array
    {
        if (!file_exists($path)) {
            throw new RuntimeException("Environment file not found: {$path}");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException("Failed to read environment file: {$path}");
        }

        return $this->parse($content);
    }

    /**
     * Get the default .env file path for this application.
     *
     * The app always looks in the same location, making deployment simple:
     * just copy the correct .env file to backend/.env
     *
     * @return string Absolute path to the default .env file
     */
    public function getDefaultPath(): string
    {
        // Resolve to backend/.env
        return dirname(__DIR__, 2) . '/.env';
    }

    /**
     * Parse .env file content into an associative array.
     *
     * @param string $content Raw .env file content
     * @return array<string, string> Parsed key-value pairs
     */
    private function parse(string $content): array
    {
        $config = [];
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip empty lines
            if ($line === '') {
                continue;
            }

            // Skip comment lines
            if (str_starts_with($line, '#')) {
                continue;
            }

            // Skip lines without =
            if (!str_contains($line, '=')) {
                continue;
            }

            // Parse KEY=value
            $parsed = $this->parseLine($line);
            if ($parsed !== null) {
                [$key, $value] = $parsed;
                $config[$key] = $value;
            }
        }

        return $config;
    }

    /**
     * Parse a single line into key and value.
     *
     * @param string $line Line containing KEY=value
     * @return array{0: string, 1: string}|null [key, value] or null if invalid
     */
    private function parseLine(string $line): ?array
    {
        // Split on first = only (value may contain =)
        $pos = strpos($line, '=');
        if ($pos === false) {
            return null;
        }

        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));

        // Handle quoted values
        $value = $this->unquote($value);

        // Strip inline comments (but not inside quotes - already handled)
        $value = $this->stripInlineComment($value);

        return [$key, $value];
    }

    /**
     * Remove surrounding quotes from a value.
     *
     * @param string $value Value possibly wrapped in quotes
     * @return string Unquoted value
     */
    private function unquote(string $value): string
    {
        // Double quotes
        if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
            return substr($value, 1, -1);
        }

        // Single quotes
        if (str_starts_with($value, "'") && str_ends_with($value, "'")) {
            return substr($value, 1, -1);
        }

        return $value;
    }

    /**
     * Strip inline comments from a value.
     *
     * @param string $value Value possibly containing # comment
     * @return string Value without inline comment
     */
    private function stripInlineComment(string $value): string
    {
        // Find # that's not inside quotes (simplified: just find first # preceded by space)
        $pos = strpos($value, ' #');
        if ($pos !== false) {
            return trim(substr($value, 0, $pos));
        }

        return $value;
    }
}

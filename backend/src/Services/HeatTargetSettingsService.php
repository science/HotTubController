<?php

declare(strict_types=1);

namespace HotTub\Services;

/**
 * Service for managing heat-target settings.
 *
 * Stores shared settings for the "heat to target" feature:
 * - enabled: whether to use heat-to-target mode
 * - target_temp_f: the target temperature in Fahrenheit
 *
 * These settings are admin-configurable and affect all users.
 */
class HeatTargetSettingsService
{
    private string $settingsFile;
    private array $settings;

    public const MIN_TEMP_F = 80.0;
    public const MAX_TEMP_F = 110.0;
    public const DEFAULT_TEMP_F = 103.0;
    public const DEFAULT_TIMEZONE = 'America/Los_Angeles';
    public const DEFAULT_SCHEDULE_MODE = 'start_at';
    public const VALID_SCHEDULE_MODES = ['start_at', 'ready_by'];

    public function __construct(string $settingsFile)
    {
        $this->settingsFile = $settingsFile;
        $this->settings = $this->loadSettings();
    }

    /**
     * Get all settings.
     *
     * @return array{enabled: bool, target_temp_f: float, updated_at: string|null}
     */
    public function getSettings(): array
    {
        return [
            'enabled' => $this->settings['enabled'] ?? false,
            'target_temp_f' => $this->settings['target_temp_f'] ?? self::DEFAULT_TEMP_F,
            'timezone' => $this->settings['timezone'] ?? self::DEFAULT_TIMEZONE,
            'schedule_mode' => $this->settings['schedule_mode'] ?? self::DEFAULT_SCHEDULE_MODE,
            'updated_at' => $this->settings['updated_at'] ?? null,
        ];
    }

    /**
     * Check if heat-to-target mode is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->settings['enabled'] ?? false;
    }

    /**
     * Get the target temperature in Fahrenheit.
     */
    public function getTargetTempF(): float
    {
        return $this->settings['target_temp_f'] ?? self::DEFAULT_TEMP_F;
    }

    /**
     * Get the configured timezone.
     */
    public function getTimezone(): string
    {
        return $this->settings['timezone'] ?? self::DEFAULT_TIMEZONE;
    }

    /**
     * Get the schedule mode ('start_at' or 'ready_by').
     */
    public function getScheduleMode(): string
    {
        return $this->settings['schedule_mode'] ?? self::DEFAULT_SCHEDULE_MODE;
    }

    /**
     * Update the schedule mode.
     *
     * @throws \InvalidArgumentException if mode is not valid
     */
    public function updateScheduleMode(string $mode): void
    {
        if (!in_array($mode, self::VALID_SCHEDULE_MODES, true)) {
            throw new \InvalidArgumentException("Invalid schedule mode: {$mode}");
        }

        $this->settings['schedule_mode'] = $mode;
        $this->saveSettings();
    }

    /**
     * Update the timezone setting.
     *
     * @throws \InvalidArgumentException if timezone is not a valid IANA identifier
     */
    public function updateTimezone(string $timezone): void
    {
        if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            throw new \InvalidArgumentException("Invalid timezone: {$timezone}");
        }

        $this->settings['timezone'] = $timezone;
        $this->saveSettings();
    }

    /**
     * Update settings.
     *
     * @param bool $enabled Whether heat-to-target mode is enabled
     * @param float $targetTempF Target temperature in Fahrenheit
     * @throws \InvalidArgumentException if temperature is out of range
     */
    public function updateSettings(bool $enabled, float $targetTempF): void
    {
        if ($targetTempF < self::MIN_TEMP_F || $targetTempF > self::MAX_TEMP_F) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Target temperature must be between %.1f°F and %.1f°F',
                    self::MIN_TEMP_F,
                    self::MAX_TEMP_F
                )
            );
        }

        $this->settings['enabled'] = $enabled;
        $this->settings['target_temp_f'] = $targetTempF;
        $this->saveSettings();
    }

    /**
     * Load settings from file.
     */
    private function loadSettings(): array
    {
        if (!file_exists($this->settingsFile)) {
            return [
                'enabled' => false,
                'target_temp_f' => self::DEFAULT_TEMP_F,
                'timezone' => self::DEFAULT_TIMEZONE,
                'updated_at' => null,
            ];
        }

        $content = file_get_contents($this->settingsFile);
        if ($content === false) {
            return [
                'enabled' => false,
                'target_temp_f' => self::DEFAULT_TEMP_F,
                'timezone' => self::DEFAULT_TIMEZONE,
                'updated_at' => null,
            ];
        }

        $data = json_decode($content, true);
        return is_array($data) ? $data : [
            'enabled' => false,
            'target_temp_f' => self::DEFAULT_TEMP_F,
            'updated_at' => null,
        ];
    }

    /**
     * Save settings to file.
     */
    private function saveSettings(): void
    {
        $this->settings['updated_at'] = date('c');
        $this->ensureDirectory();
        file_put_contents($this->settingsFile, json_encode($this->settings, JSON_PRETTY_PRINT));
    }

    /**
     * Ensure the settings directory exists.
     */
    private function ensureDirectory(): void
    {
        $dir = dirname($this->settingsFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use InvalidArgumentException;
use RuntimeException;

/**
 * VCR Cassette Generator
 *
 * Generates VCR cassettes programmatically with temperature sequences
 * for testing hot tub heating cycles without live API calls.
 */
class VCRCassetteGenerator
{
    private const HEATING_RATE_F_PER_MINUTE = 0.5; // 1°F every 2 minutes

    private TemperatureSequenceBuilder $sequenceBuilder;
    private string $templatesPath;
    private string $generatedPath;

    public function __construct(?string $cassettesBasePath = null)
    {
        $basePath = $cassettesBasePath ?: __DIR__ . '/../cassettes';
        $this->templatesPath = $basePath . '/templates';
        $this->generatedPath = $basePath . '/generated';

        $this->sequenceBuilder = new TemperatureSequenceBuilder();

        // Ensure directories exist
        if (!is_dir($this->templatesPath)) {
            mkdir($this->templatesPath, 0755, true);
        }
        if (!is_dir($this->generatedPath)) {
            mkdir($this->generatedPath, 0755, true);
        }
    }

    /**
     * Generate a complete heating cycle cassette
     *
     * @param float $startTempF Starting temperature in Fahrenheit
     * @param float $targetTempF Target temperature in Fahrenheit
     * @param string $deviceId Device UUID for the cassette
     * @param int $intervalMinutes Interval between temperature readings
     * @return string Path to generated cassette file
     */
    public function generateHeatingCycle(
        float $startTempF,
        float $targetTempF,
        string $deviceId,
        int $intervalMinutes = 5
    ): string {
        if ($startTempF >= $targetTempF) {
            throw new InvalidArgumentException("Start temperature must be less than target temperature");
        }

        $sequence = $this->sequenceBuilder->buildHeatingSequence(
            $startTempF,
            $targetTempF,
            $intervalMinutes
        );

        $cassetteName = sprintf(
            'heating-cycle-%d-%d.yml',
            (int) $startTempF,
            (int) $targetTempF
        );

        return $this->generateCassetteFromSequence($sequence, $deviceId, $cassetteName);
    }

    /**
     * Generate precision monitoring cassette (when within 1°F of target)
     *
     * @param float $currentTempF Current temperature (should be within 1°F of target)
     * @param float $targetTempF Target temperature
     * @param string $deviceId Device UUID
     * @return string Path to generated cassette file
     */
    public function generatePrecisionMonitoring(
        float $currentTempF,
        float $targetTempF,
        string $deviceId
    ): string {
        $tempDiff = abs($targetTempF - $currentTempF);
        if ($tempDiff > 1.0) {
            throw new InvalidArgumentException("Current temperature must be within 1°F of target for precision monitoring");
        }

        $sequence = $this->sequenceBuilder->buildPrecisionSequence(
            $currentTempF,
            $targetTempF,
            15 // 15-second intervals for precision monitoring
        );

        $cassetteName = sprintf(
            'precision-monitoring-%d.yml',
            (int) $currentTempF
        );

        return $this->generateCassetteFromSequence($sequence, $deviceId, $cassetteName);
    }

    /**
     * Generate cassette for various starting temperatures
     *
     * @param array $startTemperatures Array of starting temperatures
     * @param float $targetTempF Target temperature
     * @param string $deviceId Device UUID
     * @return string Path to generated cassette file
     */
    public function generateVariousStartTemps(
        array $startTemperatures,
        float $targetTempF,
        string $deviceId
    ): string {
        $allSequences = [];

        foreach ($startTemperatures as $startTemp) {
            $sequence = $this->sequenceBuilder->buildHeatingSequence(
                $startTemp,
                $targetTempF,
                5 // 5-minute intervals
            );
            $allSequences = array_merge($allSequences, $sequence);
        }

        $cassetteName = 'various-start-temps.yml';
        return $this->generateCassetteFromSequence($allSequences, $deviceId, $cassetteName);
    }

    /**
     * Generate cassette from temperature sequence
     *
     * @param array $sequence Temperature sequence data
     * @param string $deviceId Device UUID
     * @param string $cassetteName Output cassette filename
     * @return string Path to generated cassette file
     */
    private function generateCassetteFromSequence(
        array $sequence,
        string $deviceId,
        string $cassetteName
    ): string {
        $template = $this->loadTemplate('base-response.yml.template');

        $interactions = [];

        foreach ($sequence as $index => $reading) {
            $interaction = $this->createInteractionFromTemplate(
                $template,
                $reading,
                $deviceId,
                $index
            );
            $interactions[] = $interaction;
        }

        $cassette = [
            'http_interactions' => $interactions
        ];

        $cassetteFile = $this->generatedPath . '/' . $cassetteName;
        $yamlContent = $this->arrayToYaml($cassette);

        if (file_put_contents($cassetteFile, $yamlContent) === false) {
            throw new RuntimeException("Failed to write cassette file: {$cassetteFile}");
        }

        return $cassetteFile;
    }

    /**
     * Create interaction from template with temperature data
     */
    private function createInteractionFromTemplate(
        array $template,
        array $reading,
        string $deviceId,
        int $index
    ): array {
        $interaction = $template;

        // Replace placeholders in response body
        $responseBody = $interaction['response']['body']['string'];

        $replacements = [
            '{{WATER_TEMP_C}}' => $reading['water_temp_c'],
            '{{AMBIENT_TEMP_C}}' => $reading['ambient_temp_c'],
            '{{TIMESTAMP}}' => $reading['timestamp_ticks'],
            '{{BATTERY_VOLTAGE}}' => $reading['battery_voltage'],
            '{{DEVICE_ID}}' => $deviceId,
            '{{SIGNAL_STRENGTH}}' => $reading['signal_strength']
        ];

        foreach ($replacements as $placeholder => $value) {
            $responseBody = str_replace($placeholder, (string) $value, $responseBody);
        }

        $interaction['response']['body']['string'] = $responseBody;

        // Update request/response timestamps
        $httpDateTime = date('D, d M Y H:i:s T', $reading['unix_timestamp']);

        if (isset($interaction['response']['headers']['Date'])) {
            $interaction['response']['headers']['Date'] = [$httpDateTime];
        }

        return $interaction;
    }

    /**
     * Load template file
     */
    private function loadTemplate(string $templateName): array
    {
        $templatePath = $this->templatesPath . '/' . $templateName;

        if (!file_exists($templatePath)) {
            throw new RuntimeException("Template not found: {$templatePath}");
        }

        $content = file_get_contents($templatePath);
        if ($content === false) {
            throw new RuntimeException("Failed to read template: {$templatePath}");
        }

        // For now, return a simple template structure since we don't have YAML parser
        // In a real implementation, you'd use symfony/yaml or similar
        return $this->createDefaultTemplate();
    }

    /**
     * Create default template structure
     */
    private function createDefaultTemplate(): array
    {
        return [
            'request' => [
                'method' => 'POST',
                'uri' => 'https://wirelesstag.net/ethClient.asmx/GetTagList',
                'body' => [
                    'encoding' => 'UTF-8',
                    'string' => '{"id":"{{DEVICE_ID}}"}'
                ],
                'headers' => [
                    'Content-Type' => ['application/json; charset=utf-8'],
                    'Authorization' => ['Bearer ***MASKED***'],
                    'User-Agent' => ['HotTubController/1.0'],
                    'Accept' => ['application/json']
                ]
            ],
            'response' => [
                'status' => [
                    'code' => 200,
                    'message' => 'OK'
                ],
                'headers' => [
                    'Content-Type' => ['application/json; charset=utf-8'],
                    'Date' => ['Thu, 05 Sep 2024 21:00:00 GMT']
                ],
                'body' => [
                    'encoding' => 'UTF-8',
                    'string' => '{"d":[{"uuid":"{{DEVICE_ID}}","name":"Hot tub temperature","temperature":{{WATER_TEMP_C}},"cap":{{AMBIENT_TEMP_C}},"lastComm":{{TIMESTAMP}},"batteryVolt":{{BATTERY_VOLTAGE}},"signaldBm":{{SIGNAL_STRENGTH}},"alive":true}]}'
                ]
            ],
            'recorded_at' => 'Thu, 05 Sep 2024 21:00:00 GMT'
        ];
    }

    /**
     * Convert array to YAML format (simplified)
     */
    private function arrayToYaml(array $data): string
    {
        // Simple YAML serialization for VCR cassettes
        // In production, use symfony/yaml component
        return $this->arrayToYamlRecursive($data, 0);
    }

    /**
     * Recursive YAML conversion helper
     */
    private function arrayToYamlRecursive(mixed $data, int $depth): string
    {
        $indent = str_repeat('  ', $depth);
        $yaml = '';

        if (is_array($data)) {
            foreach ($data as $key => $value) {
                if (is_numeric($key)) {
                    $yaml .= $indent . "- ";
                    if (is_array($value) || is_object($value)) {
                        $yaml .= "\n" . $this->arrayToYamlRecursive($value, $depth + 1);
                    } else {
                        $yaml .= $this->scalarToYaml($value) . "\n";
                    }
                } else {
                    $yaml .= $indent . $key . ': ';
                    if (is_array($value) || is_object($value)) {
                        $yaml .= "\n" . $this->arrayToYamlRecursive($value, $depth + 1);
                    } else {
                        $yaml .= $this->scalarToYaml($value) . "\n";
                    }
                }
            }
        }

        return $yaml;
    }

    /**
     * Convert scalar value to YAML format
     */
    private function scalarToYaml(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            // Simple string quoting - escape if needed
            if (strpos($value, '"') !== false || strpos($value, "\n") !== false) {
                return '"' . str_replace('"', '\"', $value) . '"';
            }
            return $value;
        }

        return (string) $value;
    }

    /**
     * Calculate heating duration in minutes
     */
    public function calculateHeatingDuration(float $startTempF, float $targetTempF): int
    {
        $tempRise = $targetTempF - $startTempF;
        return (int) ceil($tempRise / self::HEATING_RATE_F_PER_MINUTE);
    }

    /**
     * Get heating rate in °F per minute
     */
    public function getHeatingRate(): float
    {
        return self::HEATING_RATE_F_PER_MINUTE;
    }
}

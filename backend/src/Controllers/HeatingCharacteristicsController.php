<?php

declare(strict_types=1);

namespace HotTub\Controllers;

use HotTub\Services\HeatingCharacteristicsService;

class HeatingCharacteristicsController
{
    private string $resultsFile;
    private string $logsDir;
    private string $eventLogFile;

    public function __construct(
        private HeatingCharacteristicsService $service,
        string $resultsFile,
        string $logsDir,
        string $eventLogFile
    ) {
        $this->resultsFile = $resultsFile;
        $this->logsDir = $logsDir;
        $this->eventLogFile = $eventLogFile;
    }

    public function get(): array
    {
        if (!file_exists($this->resultsFile)) {
            return [
                'status' => 200,
                'body' => ['results' => null, 'message' => 'No analysis has been generated yet'],
            ];
        }

        $results = json_decode(file_get_contents($this->resultsFile), true);

        return [
            'status' => 200,
            'body' => ['results' => $results],
        ];
    }

    public function generate(): array
    {
        // Find all temperature log files
        $tempLogFiles = glob($this->logsDir . '/temperature-*.log') ?: [];

        $results = $this->service->generate($tempLogFiles, $this->eventLogFile);

        // Store results
        $dir = dirname($this->resultsFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->resultsFile, json_encode($results, JSON_PRETTY_PRINT));

        return [
            'status' => 200,
            'body' => ['results' => $results],
        ];
    }
}

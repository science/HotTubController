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

    public function generate(array $params = []): array
    {
        $lookbackDays = isset($params['lookback_days']) ? (int) $params['lookback_days'] : null;

        // Compute date range from lookback_days
        $startDate = null;
        $endDate = null;
        if ($lookbackDays !== null && $lookbackDays > 0) {
            $startDate = date('Y-m-d', strtotime("-{$lookbackDays} days"));
            $endDate = date('Y-m-d');
        }

        // Find temperature log files, filtered by date in filename
        $tempLogFiles = glob($this->logsDir . '/temperature-*.log') ?: [];
        if ($startDate !== null) {
            $tempLogFiles = array_values(array_filter($tempLogFiles, function ($f) use ($startDate, $endDate) {
                if (preg_match('/temperature-(\d{4}-\d{2}-\d{2})\.log/', basename($f), $m)) {
                    $date = $m[1];
                    if ($startDate && $date < $startDate) return false;
                    if ($endDate && $date > $endDate) return false;
                    return true;
                }
                return false;
            }));
        }

        $results = $this->service->generate($tempLogFiles, $this->eventLogFile, $startDate, $endDate);

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

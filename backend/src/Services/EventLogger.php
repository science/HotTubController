<?php

declare(strict_types=1);

namespace HotTub\Services;

class EventLogger
{
    public function __construct(
        private string $logFile
    ) {}

    public function log(string $action, array $data = []): void
    {
        $entry = [
            'timestamp' => date('c'),
            'action' => $action,
        ];

        if (!empty($data)) {
            $entry['data'] = $data;
        }

        $line = json_encode($entry) . PHP_EOL;
        file_put_contents($this->logFile, $line, FILE_APPEND);
    }
}

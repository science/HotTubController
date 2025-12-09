<?php

declare(strict_types=1);

namespace HotTub\Controllers;

use HotTub\Services\EventLogger;

class EquipmentController
{
    private EventLogger $logger;

    public function __construct(string $logFile)
    {
        $this->logger = new EventLogger($logFile);
    }

    public function health(): array
    {
        return [
            'status' => 200,
            'body' => ['status' => 'ok'],
        ];
    }

    public function heaterOn(): array
    {
        $timestamp = date('c');
        $this->logger->log('heater_on');

        return [
            'status' => 200,
            'body' => [
                'success' => true,
                'action' => 'heater_on',
                'timestamp' => $timestamp,
            ],
        ];
    }

    public function heaterOff(): array
    {
        $timestamp = date('c');
        $this->logger->log('heater_off');

        return [
            'status' => 200,
            'body' => [
                'success' => true,
                'action' => 'heater_off',
                'timestamp' => $timestamp,
            ],
        ];
    }

    public function pumpRun(): array
    {
        $timestamp = date('c');
        $duration = 7200; // 2 hours in seconds
        $this->logger->log('pump_run', ['duration' => $duration]);

        return [
            'status' => 200,
            'body' => [
                'success' => true,
                'action' => 'pump_run',
                'duration' => $duration,
                'timestamp' => $timestamp,
            ],
        ];
    }
}

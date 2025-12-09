<?php

declare(strict_types=1);

namespace HotTub\Services;

/**
 * Console logger for IFTTT operations.
 *
 * Outputs formatted messages to the console to clearly indicate
 * whether operations are running in stub or live mode.
 */
class ConsoleLogger
{
    /** @var resource */
    private $output;

    /**
     * @param resource|null $output Output stream (defaults to STDOUT)
     */
    public function __construct($output = null)
    {
        $this->output = $output ?? STDOUT;
    }

    /**
     * Log a stub (simulated) operation.
     */
    public function stub(string $eventName, int $durationMs): void
    {
        $message = sprintf(
            "[STUB] IFTTT trigger: %s (simulated, %dms)\n",
            $eventName,
            $durationMs
        );
        $this->write($message);
    }

    /**
     * Log a live operation.
     */
    public function live(string $eventName, int $httpCode, int $durationMs): void
    {
        $message = sprintf(
            "[LIVE] IFTTT trigger: %s → HTTP %d (%dms)\n",
            $eventName,
            $httpCode,
            $durationMs
        );
        $this->write($message);
    }

    /**
     * Log initialization with mode.
     */
    public function init(string $mode): void
    {
        $message = sprintf(
            "[INIT] IFTTT client mode: %s\n",
            $mode
        );
        $this->write($message);
    }

    /**
     * Write message to output stream.
     */
    private function write(string $message): void
    {
        fwrite($this->output, $message);
    }
}

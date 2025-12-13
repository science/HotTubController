<?php

declare(strict_types=1);

namespace HotTub\Tests\Services;

use HotTub\Services\RequestLogger;
use PHPUnit\Framework\TestCase;

class RequestLoggerTest extends TestCase
{
    private string $logFile;
    private RequestLogger $logger;

    protected function setUp(): void
    {
        $this->logFile = sys_get_temp_dir() . '/request-log-test-' . uniqid() . '.log';
        $this->logger = new RequestLogger($this->logFile);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->logFile)) {
            unlink($this->logFile);
        }
    }

    // ========== log() Tests ==========

    public function testLogCreatesLogFileIfNotExists(): void
    {
        $this->logger->log(
            method: 'GET',
            uri: '/api/health',
            statusCode: 200,
            ip: '192.168.1.1'
        );

        $this->assertFileExists($this->logFile);
    }

    public function testLogWritesJsonLine(): void
    {
        $this->logger->log(
            method: 'POST',
            uri: '/api/equipment/heater/on',
            statusCode: 200,
            ip: '10.0.0.1'
        );

        $content = file_get_contents($this->logFile);
        $entry = json_decode(trim($content), true);

        $this->assertIsArray($entry);
        $this->assertEquals('POST', $entry['method']);
        $this->assertEquals('/api/equipment/heater/on', $entry['uri']);
        $this->assertEquals(200, $entry['status']);
        $this->assertEquals('10.0.0.1', $entry['ip']);
    }

    public function testLogIncludesTimestamp(): void
    {
        $before = time();
        $this->logger->log(
            method: 'GET',
            uri: '/api/health',
            statusCode: 200,
            ip: '127.0.0.1'
        );
        $after = time();

        $entry = json_decode(file_get_contents($this->logFile), true);

        $this->assertArrayHasKey('timestamp', $entry);
        $timestamp = strtotime($entry['timestamp']);
        $this->assertGreaterThanOrEqual($before, $timestamp);
        $this->assertLessThanOrEqual($after, $timestamp);
    }

    public function testLogIncludesResponseTime(): void
    {
        $this->logger->log(
            method: 'GET',
            uri: '/api/temperature',
            statusCode: 200,
            ip: '192.168.1.100',
            responseTimeMs: 125.5
        );

        $entry = json_decode(file_get_contents($this->logFile), true);

        $this->assertArrayHasKey('response_time_ms', $entry);
        $this->assertEquals(125.5, $entry['response_time_ms']);
    }

    public function testLogAppendsMultipleEntries(): void
    {
        $this->logger->log('GET', '/api/health', 200, '10.0.0.1');
        $this->logger->log('POST', '/api/schedule', 201, '10.0.0.2');
        $this->logger->log('DELETE', '/api/schedule/job-123', 200, '10.0.0.3');

        $lines = array_filter(explode("\n", file_get_contents($this->logFile)));
        $this->assertCount(3, $lines);
    }

    public function testLogIncludesOptionalUsername(): void
    {
        $this->logger->log(
            method: 'POST',
            uri: '/api/equipment/heater/on',
            statusCode: 200,
            ip: '192.168.1.1',
            username: 'admin'
        );

        $entry = json_decode(file_get_contents($this->logFile), true);

        $this->assertArrayHasKey('user', $entry);
        $this->assertEquals('admin', $entry['user']);
    }

    public function testLogOmitsUsernameWhenNotProvided(): void
    {
        $this->logger->log(
            method: 'GET',
            uri: '/api/health',
            statusCode: 200,
            ip: '127.0.0.1'
        );

        $entry = json_decode(file_get_contents($this->logFile), true);

        $this->assertArrayNotHasKey('user', $entry);
    }

    public function testLogIncludesOptionalErrorMessage(): void
    {
        $this->logger->log(
            method: 'POST',
            uri: '/api/schedule',
            statusCode: 400,
            ip: '192.168.1.1',
            error: 'Invalid action: bad-action'
        );

        $entry = json_decode(file_get_contents($this->logFile), true);

        $this->assertArrayHasKey('error', $entry);
        $this->assertEquals('Invalid action: bad-action', $entry['error']);
    }

    public function testLogCreatesDirectoryIfNotExists(): void
    {
        $deepPath = sys_get_temp_dir() . '/subdir-' . uniqid() . '/logs/api.log';
        $logger = new RequestLogger($deepPath);

        $logger->log('GET', '/api/health', 200, '127.0.0.1');

        $this->assertFileExists($deepPath);

        // Cleanup
        unlink($deepPath);
        rmdir(dirname($deepPath));
        rmdir(dirname(dirname($deepPath)));
    }

    // ========== Sensitive Data Tests ==========

    public function testLogDoesNotIncludeRequestBody(): void
    {
        // Request body might contain passwords, tokens, etc.
        $this->logger->log(
            method: 'POST',
            uri: '/api/auth/login',
            statusCode: 200,
            ip: '192.168.1.1'
        );

        $entry = json_decode(file_get_contents($this->logFile), true);

        $this->assertArrayNotHasKey('body', $entry);
        $this->assertArrayNotHasKey('request_body', $entry);
    }

    // ========== getLogPath() Tests ==========

    public function testGetLogPathReturnsConfiguredPath(): void
    {
        $this->assertEquals($this->logFile, $this->logger->getLogPath());
    }
}

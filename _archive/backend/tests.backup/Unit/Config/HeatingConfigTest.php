<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use HotTubController\Config\HeatingConfig;
use InvalidArgumentException;
use RuntimeException;

class HeatingConfigTest extends TestCase
{
    private string $tempConfigFile;

    protected function setUp(): void
    {
        parent::setUp();

        // Use a temporary file for testing persistence
        $this->tempConfigFile = sys_get_temp_dir() . '/heating-config-test-' . uniqid() . '.json';

        // Backup and clear environment
        $this->clearEnvironmentVars();
    }

    protected function tearDown(): void
    {
        // Clean up temp files
        if (file_exists($this->tempConfigFile)) {
            unlink($this->tempConfigFile);
        }

        parent::tearDown();
    }

    public function testDefaultConfiguration(): void
    {
        $config = new HeatingConfig();

        $this->assertEquals(0.5, $config->getHeatingRate());
        $this->assertEquals('fahrenheit_per_minute', $config->getHeatingRateUnit());
        $this->assertEquals(0.1, $config->getMinHeatingRate());
        $this->assertEquals(2.0, $config->getMaxHeatingRate());
        $this->assertEquals(['fahrenheit_per_minute'], $config->getSupportedUnits());
    }

    public function testEnvironmentConfiguration(): void
    {
        // Set environment variable
        $_ENV['HOT_TUB_HEATING_RATE'] = '0.7';

        $config = new HeatingConfig();

        $this->assertEquals(0.7, $config->getHeatingRate());
        $this->assertEquals('fahrenheit_per_minute', $config->getHeatingRateUnit());
    }

    public function testSetValidHeatingRate(): void
    {
        $config = new HeatingConfig();

        $config->setHeatingRate(0.8, 'fahrenheit_per_minute');

        $this->assertEquals(0.8, $config->getHeatingRate());
        $this->assertEquals('fahrenheit_per_minute', $config->getHeatingRateUnit());
    }

    public function testSetHeatingRateTooLow(): void
    {
        $config = new HeatingConfig();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Heating rate must be between 0.1 and 2.0 degrees per minute, got 0.05');

        $config->setHeatingRate(0.05, 'fahrenheit_per_minute');
    }

    public function testSetHeatingRateTooHigh(): void
    {
        $config = new HeatingConfig();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Heating rate must be between 0.1 and 2.0 degrees per minute, got 2.5');

        $config->setHeatingRate(2.5, 'fahrenheit_per_minute');
    }

    public function testSetInvalidUnit(): void
    {
        $config = new HeatingConfig();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unit "celsius_per_minute" is not supported. Supported units: fahrenheit_per_minute');

        $config->setHeatingRate(0.5, 'celsius_per_minute');
    }

    public function testValidateHeatingRate(): void
    {
        $config = new HeatingConfig();

        $this->assertTrue($config->validateHeatingRate(0.1));
        $this->assertTrue($config->validateHeatingRate(0.5));
        $this->assertTrue($config->validateHeatingRate(2.0));
    }

    public function testValidateUnit(): void
    {
        $config = new HeatingConfig();

        $this->assertTrue($config->validateUnit('fahrenheit_per_minute'));
    }

    public function testToArray(): void
    {
        $config = new HeatingConfig();
        $config->setHeatingRate(0.6, 'fahrenheit_per_minute');

        $array = $config->toArray();

        $expected = [
            'heating_rate' => 0.6,
            'unit' => 'fahrenheit_per_minute',
            'min_allowed' => 0.1,
            'max_allowed' => 2.0,
            'supported_units' => ['fahrenheit_per_minute']
        ];

        $this->assertEquals($expected, $array);
    }

    public function testUpdateFromArraySuccess(): void
    {
        $config = new HeatingConfig();

        $data = [
            'heating_rate' => 0.7,
            'unit' => 'fahrenheit_per_minute'
        ];

        $config->updateFromArray($data);

        $this->assertEquals(0.7, $config->getHeatingRate());
        $this->assertEquals('fahrenheit_per_minute', $config->getHeatingRateUnit());
    }

    public function testUpdateFromArrayMissingHeatingRate(): void
    {
        $config = new HeatingConfig();

        $data = [
            'unit' => 'fahrenheit_per_minute'
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required field: heating_rate');

        $config->updateFromArray($data);
    }

    public function testUpdateFromArrayMissingUnit(): void
    {
        $config = new HeatingConfig();

        $data = [
            'heating_rate' => 0.7
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required field: unit');

        $config->updateFromArray($data);
    }

    public function testUpdateFromArrayInvalidHeatingRate(): void
    {
        $config = new HeatingConfig();

        $data = [
            'heating_rate' => 2.5,
            'unit' => 'fahrenheit_per_minute'
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Heating rate must be between 0.1 and 2.0 degrees per minute, got 2.50');

        $config->updateFromArray($data);
    }

    public function testFromArraySuccess(): void
    {
        $customConfig = [
            'heating_rate' => 0.8,
            'unit' => 'fahrenheit_per_minute'
        ];

        $config = HeatingConfig::fromArray($customConfig);

        $this->assertEquals(0.8, $config->getHeatingRate());
        $this->assertEquals('fahrenheit_per_minute', $config->getHeatingRateUnit());
    }

    public function testFromArrayWithDefaults(): void
    {
        $customConfig = [];

        $config = HeatingConfig::fromArray($customConfig);

        $this->assertEquals(0.5, $config->getHeatingRate());
        $this->assertEquals('fahrenheit_per_minute', $config->getHeatingRateUnit());
    }

    private function clearEnvironmentVars(): void
    {
        unset($_ENV['HOT_TUB_HEATING_RATE']);
    }
}

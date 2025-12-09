<?php

declare(strict_types=1);

namespace HotTub\Tests\Unit\Contracts;

use PHPUnit\Framework\TestCase;
use HotTub\Contracts\IftttClientInterface;
use ReflectionClass;

class IftttClientInterfaceTest extends TestCase
{
    public function testInterfaceExists(): void
    {
        $this->assertTrue(interface_exists(IftttClientInterface::class));
    }

    public function testInterfaceDefinesTriggerMethod(): void
    {
        $reflection = new ReflectionClass(IftttClientInterface::class);

        $this->assertTrue($reflection->hasMethod('trigger'));

        $method = $reflection->getMethod('trigger');
        $this->assertEquals(1, $method->getNumberOfParameters());

        $param = $method->getParameters()[0];
        $this->assertEquals('eventName', $param->getName());
        $this->assertEquals('string', $param->getType()->getName());

        $this->assertEquals('bool', $method->getReturnType()->getName());
    }

    public function testInterfaceDefinesGetModeMethod(): void
    {
        $reflection = new ReflectionClass(IftttClientInterface::class);

        $this->assertTrue($reflection->hasMethod('getMode'));

        $method = $reflection->getMethod('getMode');
        $this->assertEquals(0, $method->getNumberOfParameters());
        $this->assertEquals('string', $method->getReturnType()->getName());
    }
}

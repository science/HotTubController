<?php

declare(strict_types=1);

namespace HotTub\Tests\Services;

use PHPUnit\Framework\TestCase;
use HotTub\Services\DynamicTargetCalculator;

/**
 * Unit tests for DynamicTargetCalculator.
 */
class DynamicTargetCalculatorTest extends TestCase
{
    private array $defaultCalibration;

    protected function setUp(): void
    {
        $this->defaultCalibration = [
            'cold'    => ['ambient_f' => 45.0, 'water_target_f' => 104.0],
            'comfort' => ['ambient_f' => 60.0, 'water_target_f' => 102.0],
            'hot'     => ['ambient_f' => 75.0, 'water_target_f' => 100.5],
        ];
    }

    // --- calculate() tests ---

    public function testExactMatchAtComfortPoint(): void
    {
        $result = DynamicTargetCalculator::calculate(60.0, $this->defaultCalibration);
        $this->assertSame(102.0, $result['target_f']);
        $this->assertFalse($result['clamped']);
    }

    public function testExactMatchAtColdPoint(): void
    {
        $result = DynamicTargetCalculator::calculate(45.0, $this->defaultCalibration);
        $this->assertSame(104.0, $result['target_f']);
        $this->assertFalse($result['clamped']);
    }

    public function testExactMatchAtHotPoint(): void
    {
        $result = DynamicTargetCalculator::calculate(75.0, $this->defaultCalibration);
        $this->assertSame(100.5, $result['target_f']);
        $this->assertFalse($result['clamped']);
    }

    public function testInterpolationMidpointColdSegment(): void
    {
        // Midpoint of cold segment: ambient 52.5 (halfway between 45 and 60)
        // Water target: halfway between 104 and 102 = 103.0
        $result = DynamicTargetCalculator::calculate(52.5, $this->defaultCalibration);
        $this->assertEqualsWithDelta(103.0, $result['target_f'], 0.01);
        $this->assertFalse($result['clamped']);
        $this->assertSame('cold', $result['segment']);
    }

    public function testInterpolationMidpointHotSegment(): void
    {
        // Midpoint of hot segment: ambient 67.5 (halfway between 60 and 75)
        // Water target: halfway between 102 and 100.5 = 101.25
        $result = DynamicTargetCalculator::calculate(67.5, $this->defaultCalibration);
        $this->assertEqualsWithDelta(101.25, $result['target_f'], 0.01);
        $this->assertFalse($result['clamped']);
        $this->assertSame('hot', $result['segment']);
    }

    public function testInterpolationQuarterPointColdSegment(): void
    {
        // Quarter into cold segment from cold end: ambient 48.75
        // 45 + (60-45)*0.25 = 48.75
        // Water: 104 + (102-104)*0.25 = 104 - 0.5 = 103.5
        $result = DynamicTargetCalculator::calculate(48.75, $this->defaultCalibration);
        $this->assertEqualsWithDelta(103.5, $result['target_f'], 0.01);
        $this->assertFalse($result['clamped']);
    }

    public function testClampBelowColdAmbient(): void
    {
        $result = DynamicTargetCalculator::calculate(30.0, $this->defaultCalibration);
        $this->assertSame(104.0, $result['target_f']);
        $this->assertTrue($result['clamped']);
        $this->assertSame('clamp_low', $result['segment']);
    }

    public function testClampAboveHotAmbient(): void
    {
        $result = DynamicTargetCalculator::calculate(90.0, $this->defaultCalibration);
        $this->assertSame(100.5, $result['target_f']);
        $this->assertTrue($result['clamped']);
        $this->assertSame('clamp_high', $result['segment']);
    }

    public function testClampAtExtremelyLowAmbient(): void
    {
        $result = DynamicTargetCalculator::calculate(-10.0, $this->defaultCalibration);
        $this->assertSame(104.0, $result['target_f']);
        $this->assertTrue($result['clamped']);
    }

    public function testClampAtExtremelyHighAmbient(): void
    {
        $result = DynamicTargetCalculator::calculate(120.0, $this->defaultCalibration);
        $this->assertSame(100.5, $result['target_f']);
        $this->assertTrue($result['clamped']);
    }

    // --- validateCalibrationPoints() tests ---

    public function testValidCalibrationReturnsNoErrors(): void
    {
        $errors = DynamicTargetCalculator::validateCalibrationPoints($this->defaultCalibration);
        $this->assertEmpty($errors);
    }

    public function testValidationRejectsUnorderedAmbientTemps(): void
    {
        $points = [
            'cold'    => ['ambient_f' => 60.0, 'water_target_f' => 104.0],
            'comfort' => ['ambient_f' => 45.0, 'water_target_f' => 102.0],
            'hot'     => ['ambient_f' => 75.0, 'water_target_f' => 100.5],
        ];
        $errors = DynamicTargetCalculator::validateCalibrationPoints($points);
        $this->assertNotEmpty($errors);
    }

    public function testValidationRejectsEqualAmbientTemps(): void
    {
        $points = [
            'cold'    => ['ambient_f' => 60.0, 'water_target_f' => 104.0],
            'comfort' => ['ambient_f' => 60.0, 'water_target_f' => 102.0],
            'hot'     => ['ambient_f' => 75.0, 'water_target_f' => 100.5],
        ];
        $errors = DynamicTargetCalculator::validateCalibrationPoints($points);
        $this->assertNotEmpty($errors);
    }

    public function testValidationRejectsWaterTargetBelowMin(): void
    {
        $points = [
            'cold'    => ['ambient_f' => 45.0, 'water_target_f' => 79.0],
            'comfort' => ['ambient_f' => 60.0, 'water_target_f' => 102.0],
            'hot'     => ['ambient_f' => 75.0, 'water_target_f' => 100.5],
        ];
        $errors = DynamicTargetCalculator::validateCalibrationPoints($points);
        $this->assertNotEmpty($errors);
    }

    public function testValidationRejectsWaterTargetAboveMax(): void
    {
        $points = [
            'cold'    => ['ambient_f' => 45.0, 'water_target_f' => 111.0],
            'comfort' => ['ambient_f' => 60.0, 'water_target_f' => 102.0],
            'hot'     => ['ambient_f' => 75.0, 'water_target_f' => 100.5],
        ];
        $errors = DynamicTargetCalculator::validateCalibrationPoints($points);
        $this->assertNotEmpty($errors);
    }

    public function testValidationRejectsMissingColdKey(): void
    {
        $points = [
            'comfort' => ['ambient_f' => 60.0, 'water_target_f' => 102.0],
            'hot'     => ['ambient_f' => 75.0, 'water_target_f' => 100.5],
        ];
        $errors = DynamicTargetCalculator::validateCalibrationPoints($points);
        $this->assertNotEmpty($errors);
    }

    public function testValidationRejectsMissingComfortKey(): void
    {
        $points = [
            'cold' => ['ambient_f' => 45.0, 'water_target_f' => 104.0],
            'hot'  => ['ambient_f' => 75.0, 'water_target_f' => 100.5],
        ];
        $errors = DynamicTargetCalculator::validateCalibrationPoints($points);
        $this->assertNotEmpty($errors);
    }

    public function testValidationRejectsMissingAmbientField(): void
    {
        $points = [
            'cold'    => ['water_target_f' => 104.0],
            'comfort' => ['ambient_f' => 60.0, 'water_target_f' => 102.0],
            'hot'     => ['ambient_f' => 75.0, 'water_target_f' => 100.5],
        ];
        $errors = DynamicTargetCalculator::validateCalibrationPoints($points);
        $this->assertNotEmpty($errors);
    }

    public function testValidationRejectsMissingWaterTargetField(): void
    {
        $points = [
            'cold'    => ['ambient_f' => 45.0, 'water_target_f' => 104.0],
            'comfort' => ['ambient_f' => 60.0],
            'hot'     => ['ambient_f' => 75.0, 'water_target_f' => 100.5],
        ];
        $errors = DynamicTargetCalculator::validateCalibrationPoints($points);
        $this->assertNotEmpty($errors);
    }

    public function testValidationAllowsNonMonotonicWaterTargets(): void
    {
        // Water targets don't need to be monotonically decreasing
        $points = [
            'cold'    => ['ambient_f' => 45.0, 'water_target_f' => 102.0],
            'comfort' => ['ambient_f' => 60.0, 'water_target_f' => 104.0],
            'hot'     => ['ambient_f' => 75.0, 'water_target_f' => 100.5],
        ];
        $errors = DynamicTargetCalculator::validateCalibrationPoints($points);
        $this->assertEmpty($errors);
    }

    public function testValidationAcceptsQuarterDegreeWaterTargets(): void
    {
        $points = [
            'cold'    => ['ambient_f' => 45.0, 'water_target_f' => 104.25],
            'comfort' => ['ambient_f' => 60.0, 'water_target_f' => 102.5],
            'hot'     => ['ambient_f' => 75.0, 'water_target_f' => 100.75],
        ];
        $errors = DynamicTargetCalculator::validateCalibrationPoints($points);
        $this->assertEmpty($errors);
    }

    public function testValidationAcceptsBoundaryWaterTargets(): void
    {
        $points = [
            'cold'    => ['ambient_f' => 45.0, 'water_target_f' => 110.0],
            'comfort' => ['ambient_f' => 60.0, 'water_target_f' => 95.0],
            'hot'     => ['ambient_f' => 75.0, 'water_target_f' => 80.0],
        ];
        $errors = DynamicTargetCalculator::validateCalibrationPoints($points);
        $this->assertEmpty($errors);
    }
}

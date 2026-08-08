<?php namespace Cruide\StarlineApi\Tests\Models;

use PHPUnit\Framework\TestCase;
use Cruide\StarlineApi\Models\DeviceState;

/**
 * @author Alexander Tischenko (http://alex-tisch.ru)
 */
final class DeviceStateTest extends TestCase
{
    public function testDeviceId(): void
    {
        $state = new DeviceState(['device_id' => 42]);
        self::assertSame(42, $state->deviceId());
    }

    public function testIsArmed(): void
    {
        self::assertTrue((new DeviceState(['security' => ['arm' => true]]))->isArmed());
        self::assertFalse((new DeviceState(['security' => ['arm' => false]]))->isArmed());
        self::assertNull((new DeviceState([]))->isArmed());
    }

    public function testIsEngineRunning(): void
    {
        self::assertTrue((new DeviceState(['engine' => ['running' => true]]))->isEngineRunning());
        self::assertFalse((new DeviceState(['engine' => ['running' => false]]))->isEngineRunning());
    }

    public function testIsEngineRunningFallback(): void
    {
        self::assertTrue((new DeviceState(['engine' => true]))->isEngineRunning());
        self::assertFalse((new DeviceState(['engine' => false]))->isEngineRunning());
    }

    public function testTemperatures(): void
    {
        $state = new DeviceState([
            'interior_temp' => 25.5,
            'engine_temp' => 90,
        ]);

        self::assertSame(25.5, $state->interiorTemperature());
        self::assertSame(90.0, $state->engineTemperature());
    }

    public function testTemperaturesFallback(): void
    {
        $state = new DeviceState([
            'temperature' => ['interior' => 22.0, 'engine' => 85.0],
        ]);

        self::assertSame(22.0, $state->interiorTemperature());
        self::assertSame(85.0, $state->engineTemperature());
    }

    public function testBatteryVoltage(): void
    {
        $state = new DeviceState(['battery' => ['voltage' => 12.6]]);
        self::assertSame(12.6, $state->batteryVoltage());
    }

    public function testGpsCoordinates(): void
    {
        $state = new DeviceState(['gps' => ['lat' => 55.7558, 'lon' => 37.6173]]);
        self::assertSame(55.7558, $state->latitude());
        self::assertSame(37.6173, $state->longitude());
    }

    public function testMileage(): void
    {
        self::assertSame(15000, (new DeviceState(['mileage' => 15000]))->mileage());
        self::assertNull((new DeviceState([]))->mileage());
    }

    public function testUpdatedAt(): void
    {
        self::assertSame(1700000000, (new DeviceState(['timestamp' => 1700000000]))->updatedAt());
        self::assertSame(1700000000, (new DeviceState(['updated_at' => 1700000000]))->updatedAt());
        self::assertNull((new DeviceState([]))->updatedAt());
    }

    public function testGsmBalance(): void
    {
        $state = new DeviceState(['balance' => ['value' => 150.5]]);
        self::assertSame(150.5, $state->gsmBalance());
    }

    public function testRaw(): void
    {
        $raw = ['device_id' => 1, 'engine' => ['running' => true]];
        $state = new DeviceState($raw);

        self::assertSame($raw, $state->raw());
    }
}

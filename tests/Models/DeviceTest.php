<?php namespace StarlineApi\Tests\Models;

use PHPUnit\Framework\TestCase;
use StarlineApi\Models\Device;

/**
 * @author Alexander Tischenko (http://alex-tisch.ru)
 */
final class DeviceTest extends TestCase
{
    public function testIdFromDeviceId(): void
    {
        $device = new Device(['device_id' => 42]);
        self::assertSame(42, $device->id());
    }

    public function testIdFromIdFallback(): void
    {
        $device = new Device(['id' => 99]);
        self::assertSame(99, $device->id());
    }

    public function testIdReturnsNullWhenMissing(): void
    {
        $device = new Device([]);
        self::assertNull($device->id());
    }

    public function testType(): void
    {
        $device = new Device(['device_type' => 'S96 v2']);
        self::assertSame('S96 v2', $device->type());
    }

    public function testAlias(): void
    {
        $device = new Device(['alias' => 'My Car']);
        self::assertSame('My Car', $device->alias());
    }

    public function testAliasEmptyStringReturnsNull(): void
    {
        $device = new Device(['alias' => '']);
        self::assertNull($device->alias());
    }

    public function testImei(): void
    {
        $device = new Device(['imei' => '123456789012345']);
        self::assertSame('123456789012345', $device->imei());
    }

    public function testIsOnline(): void
    {
        self::assertTrue((new Device(['online' => true]))->isOnline());
        self::assertFalse((new Device(['online' => false]))->isOnline());
        self::assertNull((new Device([]))->isOnline());
    }

    public function testRaw(): void
    {
        $raw = ['device_id' => 1, 'alias' => 'Test'];
        $device = new Device($raw);

        self::assertSame($raw, $device->raw());
    }
}

<?php namespace StarlineApi\Tests\Models;

use PHPUnit\Framework\TestCase;
use StarlineApi\Models\Device;
use StarlineApi\Models\UserInfo;

/**
 * @author Alexander Tischenko (http://alex-tisch.ru)
 */
final class UserInfoTest extends TestCase
{
    public function testIdNameEmail(): void
    {
        $info = new UserInfo([
            'id' => 42,
            'name' => 'Ivan',
            'email' => 'ivan@example.com',
        ]);

        self::assertSame(42, $info->id());
        self::assertSame('Ivan', $info->name());
        self::assertSame('ivan@example.com', $info->email());
    }

    public function testNullWhenMissing(): void
    {
        $info = new UserInfo([]);

        self::assertNull($info->id());
        self::assertNull($info->name());
        self::assertNull($info->email());
    }

    public function testDevices(): void
    {
        $info = new UserInfo([
            'devices' => [
                ['device_id' => 1, 'alias' => 'Car 1'],
                ['device_id' => 2, 'alias' => 'Car 2'],
            ],
        ]);

        $devices = $info->devices();

        self::assertCount(2, $devices);
        self::assertInstanceOf(Device::class, $devices[0]);
        self::assertSame(1, $devices[0]->id());
        self::assertSame('Car 2', $devices[1]->alias());
    }

    public function testDevicesEmptyWhenMissing(): void
    {
        $info = new UserInfo([]);

        self::assertSame([], $info->devices());
    }

    public function testDevicesSkipsNonArray(): void
    {
        $info = new UserInfo([
            'devices' => [
                ['device_id' => 1],
                'not-array',
                ['device_id' => 2],
            ],
        ]);

        self::assertCount(2, $info->devices());
    }

    public function testRaw(): void
    {
        $raw = ['id' => 1, 'name' => 'Test'];
        $info = new UserInfo($raw);

        self::assertSame($raw, $info->raw());
    }
}

<?php namespace Cruide\StarlineApi\Tests\Api;

use PHPUnit\Framework\TestCase;
use Cruide\StarlineApi\Auth\Authenticator;
use Cruide\StarlineApi\Auth\InMemoryTokenStorage;
use Cruide\StarlineApi\Http\Response;
use Cruide\StarlineApi\StarlineApi;
use Cruide\StarlineApi\Tests\Support\FakeHttpClient;

/**
 * @author Alexander Tischenko (http://alex-tisch.ru)
 */
final class DeviceApiTest extends TestCase
{
    private StarlineApi $api;
    private FakeHttpClient $http;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $storage = new InMemoryTokenStorage();
        $storage->set(Authenticator::KEY_SLNET, 'slnet-x');

        $this->api = new StarlineApi(
            123,
            'secret',
            'u@example.com',
            'password',
            $this->http,
            $storage
        );
    }

    public function testState(): void
    {
        $this->http->push(new Response(200, json_encode([
            'code' => 200,
            'desc' => ['device_id' => 42, 'engine' => ['running' => true]],
        ])));

        $state = $this->api->devices()->state(42);

        self::assertSame(42, $state->deviceId());
        self::assertTrue($state->isEngineRunning());
        self::assertStringEndsWith('/json/v3/device/42/data', $this->http->requests[0]['url']);
    }

    public function testSetParam(): void
    {
        $this->http->push(new Response(200, json_encode([
            'code' => 200,
            'desc' => ['ok' => true],
        ])));

        $result = $this->api->devices()->setParam(1, ['security' => ['arm' => true]]);

        self::assertSame(['ok' => true], $result);
        self::assertSame('POST_JSON', $this->http->requests[0]['method']);
        self::assertStringEndsWith('/json/v1/device/1/set_param', $this->http->requests[0]['url']);
        self::assertSame(['security' => ['arm' => true]], $this->http->requests[0]['data']);
    }

    public function testArm(): void
    {
        $this->http->push(new Response(200, json_encode([
            'code' => 200,
            'desc' => [],
        ])));

        $this->api->devices()->arm(1);

        self::assertSame(['security' => ['arm' => true]], $this->http->requests[0]['data']);
    }

    public function testDisarm(): void
    {
        $this->http->push(new Response(200, json_encode([
            'code' => 200,
            'desc' => [],
        ])));

        $this->api->devices()->disarm(1);

        self::assertSame(['security' => ['arm' => false]], $this->http->requests[0]['data']);
    }

    public function testStartEngine(): void
    {
        $this->http->push(new Response(200, json_encode([
            'code' => 200,
            'desc' => [],
        ])));

        $this->api->devices()->startEngine(1);

        self::assertSame(['engine' => ['start' => true]], $this->http->requests[0]['data']);
    }

    public function testStopEngine(): void
    {
        $this->http->push(new Response(200, json_encode([
            'code' => 200,
            'desc' => [],
        ])));

        $this->api->devices()->stopEngine(1);

        self::assertSame(['engine' => ['stop' => true]], $this->http->requests[0]['data']);
    }

    public function testEvents(): void
    {
        $this->http->push(new Response(200, json_encode([
            'code' => 200,
            'desc' => ['events' => []],
        ])));

        $this->api->devices()->events(1, 100, 200);

        self::assertSame('GET', $this->http->requests[0]['method']);
        self::assertStringEndsWith('/json/v3/device/1/events', $this->http->requests[0]['url']);
        self::assertSame(['ts_from' => 100, 'ts_to' => 200], $this->http->requests[0]['data']);
    }

    public function testHistory(): void
    {
        $this->http->push(new Response(200, json_encode([
            'code' => 200,
            'desc' => ['history' => []],
        ])));

        $this->api->devices()->history(1, 100, 200);

        self::assertSame('GET', $this->http->requests[0]['method']);
        self::assertStringEndsWith('/json/v3/device/1/history', $this->http->requests[0]['url']);
        self::assertSame(['from' => 100, 'to' => 200], $this->http->requests[0]['data']);
    }

    public function testEventsWithExtraParams(): void
    {
        $this->http->push(new Response(200, json_encode([
            'code' => 200,
            'desc' => ['events' => []],
        ])));

        $this->api->devices()->events(1, 100, 200, ['limit' => 50]);

        self::assertSame(
            ['ts_from' => 100, 'ts_to' => 200, 'limit' => 50],
            $this->http->requests[0]['data']
        );
    }

    public function testList(): void
    {
        // getUserId()
        $storage = new InMemoryTokenStorage();
        $storage->set(Authenticator::KEY_SLNET, 'slnet-x');
        $storage->set(Authenticator::KEY_USER_TOKEN, 'hash:1');
        $this->http->push(new Response(404, 'Not Found'));
        // GET /json/v1/user/1/user_info
        $this->http->push(new Response(200, json_encode([
            'code' => 200,
            'desc' => [
                'devices' => [
                    ['device_id' => 10],
                    ['device_id' => 20],
                ],
            ],
        ])));

        $api = new StarlineApi(123, 'secret', 'u@example.com', 'password', $this->http, $storage);
        $devices = $api->devices()->list();

        self::assertCount(2, $devices);
        self::assertSame(10, $devices[0]->id());
        self::assertSame(20, $devices[1]->id());
    }
}

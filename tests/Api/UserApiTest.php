<?php namespace StarlineApi\Tests\Api;

use PHPUnit\Framework\TestCase;
use StarlineApi\Auth\Authenticator;
use StarlineApi\Auth\InMemoryTokenStorage;
use StarlineApi\Http\Response;
use StarlineApi\StarlineApi;
use StarlineApi\Tests\Support\FakeHttpClient;

/**
 * @author Alexander Tischenko (http://alex-tisch.ru)
 */
final class UserApiTest extends TestCase
{
    private StarlineApi $api;
    private InMemoryTokenStorage $storage;
    private FakeHttpClient $http;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->storage = new InMemoryTokenStorage();
        $this->storage->set(Authenticator::KEY_SLNET, 'slnet-x');

        $this->api = new StarlineApi(
            123,
            'secret',
            'u@example.com',
            'password',
            $this->http,
            $this->storage
        );
    }

    public function testId(): void
    {
        // Automatically discovered via user_token with colon
        $this->storage->set(Authenticator::KEY_USER_TOKEN, 'hash:42');
        // getUserId() tries GET /json/v1/user_info:
        $this->http->push(new Response(404, 'Not Found'));

        self::assertSame(42, $this->api->user()->id());
    }

    public function testInfo(): void
    {
        // getUserId()
        $this->storage->set(Authenticator::KEY_USER_TOKEN, 'hash:1');
        $this->http->push(new Response(404, 'Not Found'));
        // GET /json/v1/user/1/user_info
        $this->http->push(new Response(200, json_encode([
            'code' => 200,
            'desc' => ['id' => 1, 'name' => 'Ivan', 'devices' => []],
        ])));

        $info = $this->api->user()->info();

        self::assertSame('Ivan', $info->name());
        self::assertSame([], $info->devices());
    }

    public function testDevices(): void
    {
        // getUserId()
        $this->storage->set(Authenticator::KEY_USER_TOKEN, 'hash:1');
        $this->http->push(new Response(404, 'Not Found'));
        // GET /json/v1/user/1/user_info
        $this->http->push(new Response(200, json_encode([
            'code' => 200,
            'desc' => [
                'devices' => [
                    ['device_id' => 10, 'alias' => 'Car'],
                ],
            ],
        ])));

        $devices = $this->api->user()->devices();

        self::assertCount(1, $devices);
        self::assertSame(10, $devices[0]->id());
    }
}

<?php namespace Cruide\StarlineApi\Tests;

use PHPUnit\Framework\TestCase;
use Cruide\StarlineApi\Auth\Authenticator;
use Cruide\StarlineApi\Auth\InMemoryTokenStorage;
use Cruide\StarlineApi\Exceptions\StarlineApiException;
use Cruide\StarlineApi\Exceptions\StarlineAuthException;
use Cruide\StarlineApi\Http\Response;
use Cruide\StarlineApi\StarlineApi;
use Cruide\StarlineApi\Tests\Support\FakeHttpClient;

/**
 * @author Alexander Tischenko (http://alex-tisch.ru)
 */
final class StarlineApiTest extends TestCase
{
    private FakeHttpClient $http;
    private InMemoryTokenStorage $storage;
    private StarlineApi $api;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->storage = new InMemoryTokenStorage();

        // Предзагружаем slnet, чтобы избежать полной SLID-цепочки.
        $this->storage->set(Authenticator::KEY_SLNET, 'test-slnet');

        $this->api = new StarlineApi(
            123,
            'secret',
            'u@example.com',
            'password',
            $this->http,
            $this->storage
        );
    }

    public function testGetRequest(): void
    {
        $this->http->push(new Response(200, json_encode([
            'code' => 200,
            'desc' => ['result' => 'ok'],
        ])));

        $result = $this->api->get('/json/v3/test');

        self::assertSame(['result' => 'ok'], $result);
        self::assertSame('GET', $this->http->requests[0]['method']);
        self::assertStringEndsWith('/json/v3/test', $this->http->requests[0]['url']);
        self::assertSame('Cookie', array_key_first($this->http->requests[0]['headers']));
        self::assertStringContainsString('slnet=test-slnet', $this->http->requests[0]['headers']['Cookie']);
    }

    public function testPostRequest(): void
    {
        $this->http->push(new Response(200, json_encode([
            'code' => 200,
            'desc' => ['success' => true],
        ])));

        $result = $this->api->post('/json/v1/device/1/set_param', ['arm' => true]);

        self::assertSame(['success' => true], $result);
        self::assertSame('POST_JSON', $this->http->requests[0]['method']);
    }

    public function testResponseStateNotSuccessThrows(): void
    {
        $this->http->push(new Response(200, json_encode([
            'state' => 0,
            'desc' => ['message' => 'bad request'],
        ])));

        $this->expectException(StarlineApiException::class);
        $this->expectExceptionMessage('bad request');

        $this->api->get('/json/v3/test');
    }

    public function testResponseCodeNotSuccessThrows(): void
    {
        $this->http->push(new Response(200, json_encode([
            'code' => 500,
            'codestring' => 'Internal error',
        ])));

        $this->expectException(StarlineApiException::class);
        $this->expectExceptionMessage('Internal error');

        $this->api->get('/json/v3/test');
    }

    public function testHttpErrorThrows(): void
    {
        $this->http->push(new Response(500, 'Server Error'));

        $this->expectException(StarlineApiException::class);
        $this->expectExceptionMessage('Ошибка HTTP 500');

        $this->api->get('/json/v3/test');
    }

    public function testAuthRetryOn401(): void
    {
        // Первый запрос: 401 → retry
        $this->http->push(new Response(401, 'Unauthorized'));
        // SLID-переавторизация (4 шага):
        $this->http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['code' => 'code1']])));
        $this->http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['token' => 'app-token']])));
        $this->http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['user_token' => 'new-user']])));
        $this->http->push(new Response(200, json_encode(['user_id' => 1]), [
            'set-cookie' => ['slnet=NEW-SLNET; Path=/'],
        ]));
        // Повторный запрос:
        $this->http->push(new Response(200, json_encode([
            'code' => 200,
            'desc' => ['result' => 'ok'],
        ])));

        $result = $this->api->get('/json/v3/test');

        self::assertSame(['result' => 'ok'], $result);
        self::assertCount(6, $this->http->requests);
        self::assertSame('NEW-SLNET', $this->storage->get(Authenticator::KEY_SLNET));
    }

    public function testEmptyBodyReturnsEmptyArray(): void
    {
        $this->http->push(new Response(200, ''));

        $result = $this->api->get('/json/v3/test');

        self::assertSame([], $result);
    }

    public function testResponseWithoutDescReturnsFullData(): void
    {
        $this->http->push(new Response(200, json_encode([
            'state' => 1,
            'extra' => 'value',
        ])));

        $result = $this->api->get('/json/v3/test');

        self::assertSame(['state' => 1, 'extra' => 'value'], $result);
    }

    public function testAuthCode401InResponseBody(): void
    {
        // Первый запрос: code=401 в теле → StarlineAuthException → retry
        $this->http->push(new Response(200, json_encode(['code' => 401])));
        // Переавторизация (4 шага):
        $this->http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['code' => 'code1']])));
        $this->http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['token' => 'app-token']])));
        $this->http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['user_token' => 'new-user']])));
        $this->http->push(new Response(200, json_encode(['user_id' => 1]), [
            'set-cookie' => ['slnet=NEW-SLNET; Path=/'],
        ]));
        // Повторный запрос снова получает 401 → финальный выброс
        $this->http->push(new Response(200, json_encode(['code' => 401])));

        $this->expectException(StarlineAuthException::class);

        $this->api->get('/json/v3/test');
    }
}

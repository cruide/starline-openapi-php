<?php namespace Cruide\StarlineApi\Tests\Auth;

use PHPUnit\Framework\TestCase;
use Cruide\StarlineApi\Auth\Authenticator;
use Cruide\StarlineApi\Auth\InMemoryTokenStorage;
use Cruide\StarlineApi\Http\Response;
use Cruide\StarlineApi\Tests\Support\FakeHttpClient;

/**
 * @author Alexander Tischenko (http://alex-tisch.ru)
 */
final class AuthenticatorTest extends TestCase
{
    public function testFullSlidAuthChain(): void
    {
        $http = new FakeHttpClient();
        // Шаг 1: getCode
        $http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['code' => 'abc123']])));
        // Шаг 2: getToken
        $http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['token' => 'app-token']])));
        // Шаг 3: user/login
        $http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['user_token' => 'hash:42']])));
        // Шаг 4: auth.slid
        $http->push(new Response(200, json_encode(['user_id' => 42]), [
            'set-cookie' => ['slnet=SLNET-TOKEN; Path=/; HttpOnly'],
        ]));

        $auth = new Authenticator(
            $http,
            new InMemoryTokenStorage(),
            123,
            'secret',
            'user@example.com',
            'password'
        );

        self::assertSame('SLNET-TOKEN', $auth->getSlnetToken());

        // Шаг 1: getCode, secret = md5(appSecret)
        self::assertSame('GET', $http->requests[0]['method']);
        self::assertSame(md5('secret'), $http->requests[0]['data']['secret']);
        self::assertStringEndsWith('/apiV3/application/getCode', $http->requests[0]['url']);

        // Шаг 2: getToken, secret = md5(appSecret + code)
        self::assertSame('GET', $http->requests[1]['method']);
        self::assertSame(md5('secretabc123'), $http->requests[1]['data']['secret']);
        self::assertStringEndsWith('/apiV3/application/getToken', $http->requests[1]['url']);

        // Шаг 3: user/login?token=app_token, pass = sha1(password)
        self::assertSame('POST_FORM', $http->requests[2]['method']);
        self::assertStringContainsString('/apiV3/user/login?token=app-token', $http->requests[2]['url']);
        self::assertSame(sha1('password'), $http->requests[2]['data']['pass']);
        self::assertSame('user@example.com', $http->requests[2]['data']['login']);

        // Шаг 4: auth.slid, JSON с slid_token
        self::assertSame('POST_JSON', $http->requests[3]['method']);
        self::assertSame(['slid_token' => 'hash:42'], $http->requests[3]['data']);
        self::assertStringEndsWith('/json/v2/auth.slid', $http->requests[3]['url']);
    }

    public function testSlnetTokenIsCached(): void
    {
        $http = new FakeHttpClient();
        $storage = new InMemoryTokenStorage();
        $storage->set(Authenticator::KEY_SLNET, 'cached-slnet');

        $auth = new Authenticator($http, $storage, 1, 's', 'u', 'p');

        self::assertSame('cached-slnet', $auth->getSlnetToken());
        self::assertSame([], $http->requests);
    }

    public function testUserIdFromAuthSlid(): void
    {
        // user_id извлекается из ответа auth.slid
        $http = new FakeHttpClient();
        // getCode
        $http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['code' => 'abc']])));
        // getToken
        $http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['token' => 'app-token']])));
        // login
        $http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['user_token' => 'hash:42']])));
        // auth.slid — user_id на верхнем уровне
        $http->push(new Response(200, json_encode(['user_id' => 42]), [
            'set-cookie' => ['slnet=SLNET-TOKEN; Path=/'],
        ]));

        $storage = new InMemoryTokenStorage();
        $auth = new Authenticator($http, $storage, 123, 'secret', 'u', 'p');

        $auth->getSlnetToken();

        self::assertSame('42', $storage->get(Authenticator::KEY_USER_ID));
    }

    public function testUserIdFallbackFromUserToken(): void
    {
        $http = new FakeHttpClient();
        // GET /json/v1/user_info вернул 404
        $http->push(new Response(404, 'Not Found'));

        $storage = new InMemoryTokenStorage();
        $storage->set(Authenticator::KEY_SLNET, 'slnet-x');
        $storage->set(Authenticator::KEY_USER_TOKEN, 'deadbeef:777');

        $auth = new Authenticator($http, $storage, 1, 's', 'u', 'p');

        self::assertSame(777, $auth->getUserId());
    }

    public function testGetAppCode(): void
    {
        $http = new FakeHttpClient();
        $http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['code' => 'abc123']])));

        $auth = new Authenticator($http, new InMemoryTokenStorage(), 123, 'secret');

        self::assertSame('abc123', $auth->getAppCode());
        self::assertSame(md5('secret'), $http->requests[0]['data']['secret']);
        self::assertStringEndsWith('/apiV3/application/getCode', $http->requests[0]['url']);
    }

    public function testGetAppToken(): void
    {
        $http = new FakeHttpClient();
        $http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['code' => 'xyz']])));
        $http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['token' => 'app-token']])));

        $auth = new Authenticator($http, new InMemoryTokenStorage(), 123, 'secret');

        self::assertSame('app-token', $auth->getAppToken());
        self::assertSame(md5('secretxyz'), $http->requests[1]['data']['secret']);
        self::assertStringEndsWith('/apiV3/application/getToken', $http->requests[1]['url']);
    }

    public function testUserTokenIsCached(): void
    {
        $http = new FakeHttpClient();
        $storage = new InMemoryTokenStorage();
        $storage->set(Authenticator::KEY_USER_TOKEN, 'cached-user');

        $auth = new Authenticator($http, $storage, 1, 's', 'u', 'p');

        self::assertSame('cached-user', $auth->getUserToken());
        self::assertSame([], $http->requests);
    }

    public function testLoginRequiredForUserToken(): void
    {
        $http = new FakeHttpClient();
        $auth = new Authenticator($http, new InMemoryTokenStorage(), 1, 's');

        $this->expectException(\Cruide\StarlineApi\Exceptions\StarlineAuthException::class);
        $this->expectExceptionMessage('логин и пароль');

        $auth->getUserToken();
    }

    public function testResetClearsAllTokens(): void
    {
        $storage = new InMemoryTokenStorage();
        $storage->set(Authenticator::KEY_SLNET, 'x');
        $storage->set(Authenticator::KEY_USER_TOKEN, 'y');
        $storage->set(Authenticator::KEY_APP_TOKEN, 'z');
        $storage->set(Authenticator::KEY_USER_ID, '42');

        $auth = new Authenticator(new FakeHttpClient(), $storage, 1, 's');
        $auth->reset();

        self::assertNull($storage->get(Authenticator::KEY_SLNET));
        self::assertNull($storage->get(Authenticator::KEY_USER_TOKEN));
        self::assertNull($storage->get(Authenticator::KEY_APP_TOKEN));
        self::assertNull($storage->get(Authenticator::KEY_USER_ID));
    }

    public function testSetUserId(): void
    {
        $storage = new InMemoryTokenStorage();
        $auth = new Authenticator(new FakeHttpClient(), $storage, 1, 's');
        $auth->setUserId(99);

        self::assertSame('99', $storage->get(Authenticator::KEY_USER_ID));
    }

    public function testSlnetRetryWhenUserTokenExpired(): void
    {
        $http = new FakeHttpClient();
        // Первая попытка: getCode
        $http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['code' => 'code1']])));
        // getToken
        $http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['token' => 'app-token']])));
        // login — первый user_token
        $http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['user_token' => 'first-token']])));
        // auth.slid — нет cookie
        $http->push(new Response(200, '{}'));
        // Повторный login (force=true), app_token уже в кэше
        $http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['user_token' => 'second-token']])));
        // auth.slid — успех
        $http->push(new Response(200, json_encode(['user_id' => 42]), [
            'set-cookie' => ['slnet=NEW-SLNET; Path=/'],
        ]));

        $auth = new Authenticator($http, new InMemoryTokenStorage(), 123, 'secret', 'u', 'p');

        self::assertSame('NEW-SLNET', $auth->getSlnetToken());
        self::assertCount(6, $http->requests);

        // Проверяем, что повторный login использовал force
        self::assertSame(['slid_token' => 'second-token'], $http->requests[5]['data']);
    }

    public function testAppTokenIsCached(): void
    {
        $http = new FakeHttpClient();
        $storage = new InMemoryTokenStorage();
        $storage->set(Authenticator::KEY_APP_TOKEN, 'cached-app');

        $auth = new Authenticator($http, $storage, 1, 's', 'u', 'p');

        self::assertSame('cached-app', $auth->getAppToken());
        self::assertSame([], $http->requests);
    }
}

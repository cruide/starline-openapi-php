<?php namespace Cruide\StarlineApi\Tests\Auth;

use PHPUnit\Framework\TestCase;
use Cruide\StarlineApi\Auth\Authenticator;
use Cruide\StarlineApi\Auth\InMemoryTokenStorage;
use Cruide\StarlineApi\Exceptions\StarlineAuthCaptchaException;
use Cruide\StarlineApi\Exceptions\StarlineAuthException;
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

    public function testCaptchaRequiredThrowsException(): void
    {
        $http = new FakeHttpClient();
        // getCode
        $http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['code' => 'abc']])));
        // getToken
        $http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['token' => 'app-token']])));
        // login — капча
        $http->push(new Response(200, json_encode([
            'state' => 0,
            'desc' => [
                'captchaSid' => 'captcha-sid-123',
                'captchaImg' => 'https://id.starline.ru/captcha/img.png',
            ],
        ])));

        $auth = new Authenticator($http, new InMemoryTokenStorage(), 123, 'secret', 'u', 'p');

        try {
            $auth->getUserToken();
            self::fail('Expected StarlineAuthCaptchaException was not thrown.');
        } catch (StarlineAuthCaptchaException $e) {
            self::assertSame('captcha-sid-123', $e->getCaptchaSid());
            self::assertSame('https://id.starline.ru/captcha/img.png', $e->getCaptchaImg());
            self::assertNull($e->getPhone());
            self::assertTrue($e->isCaptchaRequired());
            self::assertFalse($e->isSmsRequired());
        }
    }

    public function testSmsRequiredThrowsException(): void
    {
        $http = new FakeHttpClient();
        // getCode
        $http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['code' => 'abc']])));
        // getToken
        $http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['token' => 'app-token']])));
        // login — SMS
        $http->push(new Response(200, json_encode([
            'state' => 0,
            'desc' => [
                'phone' => '+7 (999) ***-**-99',
            ],
        ])));

        $auth = new Authenticator($http, new InMemoryTokenStorage(), 123, 'secret', 'u', 'p');

        try {
            $auth->getUserToken();
            self::fail('Expected StarlineAuthCaptchaException was not thrown.');
        } catch (StarlineAuthCaptchaException $e) {
            self::assertNull($e->getCaptchaSid());
            self::assertNull($e->getCaptchaImg());
            self::assertSame('+7 (999) ***-**-99', $e->getPhone());
            self::assertFalse($e->isCaptchaRequired());
            self::assertTrue($e->isSmsRequired());
        }
    }

    public function testAutoCaptchaRetrySucceedsWhenOcrSet(): void
    {
        $ocr = new class implements \Cruide\StarlineApi\Auth\OcrInterface {
            public string $lastImage = '';

            public function decode(string $imageData): ?string
            {
                $this->lastImage = $imageData;

                return 'A1B2';
            }
        };

        $http = new FakeHttpClient();
        // getCode
        $http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['code' => 'abc']])));
        // getToken
        $http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['token' => 'app-token']])));
        // login — капча
        $http->push(new Response(200, json_encode([
            'state' => 0,
            'desc' => [
                'captchaSid' => 'sid-1',
                'captchaImg' => 'https://id.starline.ru/captcha/x.png',
            ],
        ])));
        // Скачивание капчи
        $http->push(new Response(200, 'fake-png-data'));
        // Повторный login с капчей — успех
        $http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['user_token' => 'hash:99']])));

        $auth = new Authenticator($http, new InMemoryTokenStorage(), 123, 'secret', 'u', 'p');
        $auth->setOcr($ocr);

        $token = $auth->getUserToken();

        self::assertSame('hash:99', $token);
        self::assertSame('fake-png-data', $ocr->lastImage);

        // Проверяем, что в повторном login запросе есть captcha-параметры
        self::assertSame('POST_FORM', $http->requests[4]['method']);
        self::assertSame('sid-1', $http->requests[4]['data']['captchaSid']);
        self::assertSame('A1B2', $http->requests[4]['data']['captchaCode']);
    }

    public function testAutoCaptchaFailsThenThrows(): void
    {
        // OCR возвращает null — автораспознавание не сработало
        $ocr = new class implements \Cruide\StarlineApi\Auth\OcrInterface {
            public function decode(string $imageData): ?string
            {
                return null;
            }
        };

        $http = new FakeHttpClient();
        // getCode
        $http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['code' => 'abc']])));
        // getToken
        $http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['token' => 'app-token']])));
        // login — капча
        $http->push(new Response(200, json_encode([
            'state' => 0,
            'desc' => [
                'captchaSid' => 'sid-fail',
                'captchaImg' => 'https://id.starline.ru/captcha/fail.png',
            ],
        ])));

        $auth = new Authenticator($http, new InMemoryTokenStorage(), 123, 'secret', 'u', 'p');
        $auth->setOcr($ocr);

        $this->expectException(StarlineAuthCaptchaException::class);
        $auth->getUserToken();
    }

    public function testAutoCaptchaWrongCodeThenThrows(): void
    {
        // OCR возвращает код, но капча всё равно не проходит
        $ocr = new class implements \Cruide\StarlineApi\Auth\OcrInterface {
            public function decode(string $imageData): ?string
            {
                return 'WRONG';
            }
        };

        $http = new FakeHttpClient();
        // getCode
        $http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['code' => 'abc']])));
        // getToken
        $http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['token' => 'app-token']])));
        // login — капча (первая попытка)
        $http->push(new Response(200, json_encode([
            'state' => 0,
            'desc' => [
                'captchaSid' => 'sid-wrong',
                'captchaImg' => 'https://id.starline.ru/captcha/wrong.png',
            ],
        ])));
        // Скачивание капчи
        $http->push(new Response(200, 'fake-png'));
        // Повторный login — снова капча (код неверный)
        $http->push(new Response(200, json_encode([
            'state' => 0,
            'desc' => [
                'captchaSid' => 'sid-wrong-2',
                'captchaImg' => 'https://id.starline.ru/captcha/wrong2.png',
            ],
        ])));

        $auth = new Authenticator($http, new InMemoryTokenStorage(), 123, 'secret', 'u', 'p');
        $auth->setOcr($ocr);

        $this->expectException(StarlineAuthCaptchaException::class);
        $auth->getUserToken();
    }

    public function testAutoCaptchaRetryOnlyOnce(): void
    {
        // Проверяем, что автоповтор капчи происходит только один раз,
        // даже если второй ответ тоже содержит капчу (защита от бесконечного цикла)
        $ocr = new class implements \Cruide\StarlineApi\Auth\OcrInterface {
            public int $calls = 0;

            public function decode(string $imageData): ?string
            {
                $this->calls++;

                return 'X' . $this->calls;
            }
        };

        $http = new FakeHttpClient();
        // getCode
        $http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['code' => 'abc']])));
        // getToken
        $http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['token' => 'app-token']])));
        // login — капча #1
        $http->push(new Response(200, json_encode([
            'state' => 0,
            'desc' => [
                'captchaSid' => 'sid-1',
                'captchaImg' => 'https://id.starline.ru/captcha/1.png',
            ],
        ])));
        // Скачивание капчи #1
        $http->push(new Response(200, 'png-1'));
        // login — капча #2 (ретрай не помог)
        $http->push(new Response(200, json_encode([
            'state' => 0,
            'desc' => [
                'captchaSid' => 'sid-2',
                'captchaImg' => 'https://id.starline.ru/captcha/2.png',
            ],
        ])));

        $auth = new Authenticator($http, new InMemoryTokenStorage(), 123, 'secret', 'u', 'p');
        $auth->setOcr($ocr);

        try {
            $auth->getUserToken();
            self::fail('Expected exception.');
        } catch (StarlineAuthCaptchaException $e) {
            // OCR должен быть вызван ровно 1 раз, а не 2
            self::assertSame(1, $ocr->calls);
            self::assertSame('sid-2', $e->getCaptchaSid());
        }
    }

    public function testCaptchaParamsPassedOnRetry(): void
    {
        $http = new FakeHttpClient();
        // getCode
        $http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['code' => 'code1']])));
        // getToken
        $http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['token' => 'app-token']])));
        // login с капчей — успех
        $http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['user_token' => 'hash:42']])));
        // auth.slid
        $http->push(new Response(200, json_encode(['user_id' => 42]), [
            'set-cookie' => ['slnet=SLNET-TOKEN; Path=/'],
        ]));

        $auth = new Authenticator($http, new InMemoryTokenStorage(), 123, 'secret', 'u', 'p');
        $auth->setCaptchaParams('my-captcha-sid', 'abc123');

        $auth->getSlnetToken();

        // Проверяем, что в login-запросе переданы captchaSid и captchaCode
        self::assertSame('POST_FORM', $http->requests[2]['method']);
        self::assertSame('my-captcha-sid', $http->requests[2]['data']['captchaSid']);
        self::assertSame('abc123', $http->requests[2]['data']['captchaCode']);
        self::assertSame('u', $http->requests[2]['data']['login']);
        self::assertSame(sha1('p'), $http->requests[2]['data']['pass']);
    }

    public function testSmsCodePassedOnRetry(): void
    {
        $http = new FakeHttpClient();
        // getCode
        $http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['code' => 'code1']])));
        // getToken
        $http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['token' => 'app-token']])));
        // login с SMS — успех
        $http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['user_token' => 'hash:42']])));
        // auth.slid
        $http->push(new Response(200, json_encode(['user_id' => 42]), [
            'set-cookie' => ['slnet=SLNET-TOKEN; Path=/'],
        ]));

        $auth = new Authenticator($http, new InMemoryTokenStorage(), 123, 'secret', 'u', 'p');
        $auth->setSmsCode('123456');

        $auth->getSlnetToken();

        self::assertSame('POST_FORM', $http->requests[2]['method']);
        self::assertSame('123456', $http->requests[2]['data']['smsCode']);
    }

    public function testResetClearsCaptchaParams(): void
    {
        $storage = new InMemoryTokenStorage();
        $auth = new Authenticator(new FakeHttpClient(), $storage, 1, 's', 'u', 'p');
        $auth->setCaptchaParams('sid', 'code');
        $auth->setSmsCode('123456');

        $auth->reset();

        // Запрашиваем user_token через force — должны отправиться без капчи/SMS
        $http = new FakeHttpClient();
        // getCode
        $http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['code' => 'abc']])));
        // getToken
        $http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['token' => 'app-token']])));
        // login (после reset captcha/sms сброшены)
        $http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['user_token' => 'hash:99']])));

        // Пересоздаём auth с тем же storage, но новым http
        $auth = new Authenticator($http, $storage, 1, 's', 'u', 'p');
        $auth->getUserToken(true); // force=true чтобы обойти кэш приложения

        $loginData = $http->requests[2]['data'];
        self::assertArrayNotHasKey('captchaSid', $loginData);
        self::assertArrayNotHasKey('captchaCode', $loginData);
        self::assertArrayNotHasKey('smsCode', $loginData);
        self::assertSame('u', $loginData['login']);
    }

    public function testCaptchaRetryViaStarlineApi(): void
    {
        $http = new FakeHttpClient();
        // getCode
        $http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['code' => 'abc']])));
        // getToken
        $http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['token' => 'app-token']])));
        // login с капчей — успех
        $http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['user_token' => 'hash:77']])));
        // auth.slid
        $http->push(new Response(200, json_encode(['user_id' => 77]), [
            'set-cookie' => ['slnet=SLNET; Path=/'],
        ]));

        $api = new \Cruide\StarlineApi\StarlineApi(
            appId: 123, appSecret: 'secret', login: 'u', password: 'p',
            httpClient: $http,
        );

        $api->authenticateWithCaptcha('captcha-sid', '4xY9');

        self::assertSame('POST_FORM', $http->requests[2]['method']);
        self::assertSame('captcha-sid', $http->requests[2]['data']['captchaSid']);
        self::assertSame('4xY9', $http->requests[2]['data']['captchaCode']);
    }

    public function testSmsRetryViaStarlineApi(): void
    {
        $http = new FakeHttpClient();
        // getCode
        $http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['code' => 'abc']])));
        // getToken
        $http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['token' => 'app-token']])));
        // login с SMS — успех
        $http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['user_token' => 'hash:88']])));
        // auth.slid
        $http->push(new Response(200, json_encode(['user_id' => 88]), [
            'set-cookie' => ['slnet=SLNET; Path=/'],
        ]));

        $api = new \Cruide\StarlineApi\StarlineApi(
            appId: 123, appSecret: 'secret', login: 'u', password: 'p',
            httpClient: $http,
        );

        $api->authenticateWithSms('654321');

        self::assertSame('POST_FORM', $http->requests[2]['method']);
        self::assertSame('654321', $http->requests[2]['data']['smsCode']);
    }

    public function testLastCaptchaInfoStoredOnAuthError(): void
    {
        $http = new FakeHttpClient();
        // getCode
        $http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['code' => 'abc']])));
        // getToken
        $http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['token' => 'app-token']])));
        // login — капча
        $http->push(new Response(200, json_encode([
            'state' => 0,
            'desc' => [
                'captchaSid' => 'sid-999',
                'captchaImg' => 'https://id.starline.ru/captcha/999.png',
            ],
        ])));

        $auth = new Authenticator($http, new InMemoryTokenStorage(), 123, 'secret', 'u', 'p');

        try {
            $auth->getUserToken();
        } catch (StarlineAuthCaptchaException $e) {
            self::assertSame('sid-999', $e->getCaptchaSid());
            self::assertSame('https://id.starline.ru/captcha/999.png', $e->getCaptchaImg());
        }

        self::assertSame('sid-999', $auth->getLastCaptchaSid());
        self::assertSame('https://id.starline.ru/captcha/999.png', $auth->getLastCaptchaImg());
    }

    public function testLastCaptchaClearedOnReset(): void
    {
        $http = new FakeHttpClient();
        // getCode
        $http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['code' => 'abc']])));
        // getToken
        $http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['token' => 'app-token']])));
        // login — капча
        $http->push(new Response(200, json_encode([
            'state' => 0,
            'desc' => [
                'captchaSid' => 'sid-999',
                'captchaImg' => 'https://id.starline.ru/captcha/999.png',
            ],
        ])));

        $auth = new Authenticator($http, new InMemoryTokenStorage(), 123, 'secret', 'u', 'p');

        try {
            $auth->getUserToken();
        } catch (StarlineAuthCaptchaException) {
            // expected
        }

        self::assertSame('sid-999', $auth->getLastCaptchaSid());
        $auth->reset();
        self::assertNull($auth->getLastCaptchaSid());
        self::assertNull($auth->getLastCaptchaImg());
    }

    public function testAuthenticateWithCaptchaAuto(): void
    {
        // Mock OCR that always returns 'ABCD'
        $ocr = new class implements \Cruide\StarlineApi\Auth\OcrInterface {
            public string $lastImageData = '';

            public function decode(string $imageData): ?string
            {
                $this->lastImageData = $imageData;

                return 'ABCD';
            }
        };

        $http = new FakeHttpClient();
        // Загрузка капчи
        $http->push(new Response(200, 'fake-png-bytes'));
        // getCode
        $http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['code' => 'abc']])));
        // getToken
        $http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['token' => 'app-token']])));
        // login с капчей
        $http->push(new Response(200, json_encode(['state' => 1, 'desc' => ['user_token' => 'hash:99']])));
        // auth.slid
        $http->push(new Response(200, json_encode(['user_id' => 99]), [
            'set-cookie' => ['slnet=SLNET; Path=/'],
        ]));

        $api = new \Cruide\StarlineApi\StarlineApi(
            appId: 123, appSecret: 'secret', login: 'u', password: 'p',
            httpClient: $http,
        );
        $api->authenticator()->setCaptchaParams('manual-sid-not-used', '');

        // Симулируем сохранение captchaInfo как после StarlineAuthCaptchaException
        $reflector = new \ReflectionClass($api->authenticator());
        $propSid = $reflector->getProperty('lastCaptchaSid');
        $propSid->setAccessible(true);
        $propSid->setValue($api->authenticator(), 'captcha-sid-auto');
        $propImg = $reflector->getProperty('lastCaptchaImg');
        $propImg->setAccessible(true);
        $propImg->setValue($api->authenticator(), 'https://id.starline.ru/captcha/auto.png');

        $api->authenticateWithCaptchaAuto($ocr);

        // Проверяем, что изображение скачано и передано в OCR
        self::assertSame('fake-png-bytes', $ocr->lastImageData);

        // Проверяем, что в login-запросе передан код из OCR
        self::assertSame('POST_FORM', $http->requests[3]['method']);
        self::assertSame('captcha-sid-auto', $http->requests[3]['data']['captchaSid']);
        self::assertSame('ABCD', $http->requests[3]['data']['captchaCode']);
    }

    public function testAuthenticateWithSlidTokenDirectly(): void
    {
        $http = new FakeHttpClient();
        $http->push(new Response(200, json_encode(['user_id' => 99]), [
            'set-cookie' => ['slnet=DIRECT-SLNET; Path=/'],
        ]));

        $storage = new InMemoryTokenStorage();
        $auth = new Authenticator($http, $storage, 123, 'secret');
        $auth->authenticateWithSlidToken('deadbeef:99');

        self::assertSame('DIRECT-SLNET', $storage->get(Authenticator::KEY_SLNET));
        self::assertSame('deadbeef:99', $storage->get(Authenticator::KEY_USER_TOKEN));
        self::assertSame('99', $storage->get(Authenticator::KEY_USER_ID));
        self::assertSame(['slid_token' => 'deadbeef:99'], $http->requests[0]['data']);
        self::assertStringEndsWith('/json/v2/auth.slid', $http->requests[0]['url']);
    }

    public function testAuthenticateWithSlidTokenFailsWithoutCookie(): void
    {
        $http = new FakeHttpClient();
        $http->push(new Response(200, json_encode(['user_id' => 1])));

        $auth = new Authenticator($http, new InMemoryTokenStorage(), 123, 'secret');

        $this->expectException(StarlineAuthException::class);
        $this->expectExceptionMessage('slnet');
        $auth->authenticateWithSlidToken('bad-token');
    }

    public function testSlidTokenViaStarlineApi(): void
    {
        $http = new FakeHttpClient();
        $http->push(new Response(200, json_encode(['user_id' => 55]), [
            'set-cookie' => ['slnet=API-SLNET; Path=/'],
        ]));

        $api = new \Cruide\StarlineApi\StarlineApi(
            appId: 123, appSecret: 'secret',
            httpClient: $http,
        );

        $api->authenticateWithSlidToken('token:55');

        self::assertSame(['slid_token' => 'token:55'], $http->requests[0]['data']);
    }
}

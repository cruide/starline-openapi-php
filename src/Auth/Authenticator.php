<?php namespace Cruide\StarlineApi\Auth;

use Cruide\StarlineApi\Exceptions\StarlineAuthException;
use Cruide\StarlineApi\Http\HttpClientInterface;
use Cruide\StarlineApi\Http\Response;
use Cruide\StarlineApi\Support\Arr;

/**
 * SLID-авторизация StarLine API.
 *
 * @author Alexander Tischenko (http://alex-tisch.ru)
 *
 * Цепочка согласно официальной документации
 * (https://developer.starline.ru/#/api-SLID-getAppToken):
 *
 * 1) GET  https://id.starline.ru/apiV3/application/getCode
 *    параметры: appId, secret = md5(appSecret)
 *    ответ: {"state": 1, "desc": {"code": "..."}}
 *
 * 2) GET  https://id.starline.ru/apiV3/application/getToken
 *    параметры: appId, secret = md5(appSecret + code)
 *    ответ: {"state": 1, "desc": {"token": "..."}} — токен приложения.
 *
 * 3) POST https://id.starline.ru/apiV3/user/login?token=<app_token>
 *    form-поля: login, pass = sha1(password)
 *    ответ: {"state": 1, "desc": {"user_token": "..."}} — токен пользователя.
 *
 * 4) POST https://developer.starline.ru/json/v2/auth.slid
 *    JSON-тело: {"slid_token": "<user_token>"}
 *    ответ: JSON c полем user_id + cookie `slnet`.
 *    Именно slnet передаётся во всех последующих запросах к developer.starline.ru.
 *
 * Примечание по хэшам: md5/sha1 — требование протокола StarLine,
 * а не прихоть библиотеки.
 */
final class Authenticator
{
    /** Сервер SLID (id.starline.ru). */
    public const BASE_ID_URL = 'https://id.starline.ru';

    /** Основной сервер API. */
    public const BASE_API_URL = 'https://developer.starline.ru';

    public const KEY_APP_TOKEN = 'starline.app_token';
    public const KEY_USER_TOKEN = 'starline.user_token';
    public const KEY_SLNET = 'starline.slnet';
    public const KEY_USER_ID = 'starline.user_id';

    private string $appId;

    public function __construct(
        private HttpClientInterface $http,
        private TokenStorageInterface $storage,
        int|string $appId,
        private string $appSecret,
        private ?string $login = null,
        private ?string $password = null
    ) {
        $this->appId = (string) $appId;
    }

    /**
     * Шаг 1. Получить код приложения (одноразовый, для получения токена приложения).
     *
     * @throws StarlineAuthException
     */
    public function getAppCode(): string
    {
        $response = $this->http->get(self::BASE_ID_URL . '/apiV3/application/getCode', [
            'appId' => $this->appId,
            'secret' => md5($this->appSecret),
        ]);

        $data = $this->decodeIdResponse($response, 'application/getCode');
        $code = Arr::get($data, 'desc.code');

        if (!is_string($code) || $code === '') {
            throw new StarlineAuthException('application/getCode: не получен desc.code.');
        }

        return $code;
    }

    /**
     * Шаг 2. Получить токен приложения (кэшируется в хранилище).
     *
     * @param bool $force Принудительно перевыпустить.
     * @throws StarlineAuthException
     */
    public function getAppToken(bool $force = false): string
    {
        if (!$force) {
            $cached = $this->storage->get(self::KEY_APP_TOKEN);

            if ($cached !== null && $cached !== '') {
                return $cached;
            }
        }

        $code = $this->getAppCode();

        $response = $this->http->get(self::BASE_ID_URL . '/apiV3/application/getToken', [
            'appId' => $this->appId,
            'secret' => md5($this->appSecret . $code),
        ]);

        $data = $this->decodeIdResponse($response, 'application/getToken');
        $token = Arr::get($data, 'desc.token');

        if (!is_string($token) || $token === '') {
            throw new StarlineAuthException('application/getToken: не получен desc.token.');
        }

        $this->storage->set(self::KEY_APP_TOKEN, $token);

        return $token;
    }

    /**
     * Шаг 3. Получить токен пользователя по логину/паролю (кэшируется).
     *
     * @param bool $force Принудительно перелогиниться.
     * @throws StarlineAuthException
     */
    public function getUserToken(bool $force = false): string
    {
        if (!$force) {
            $cached = $this->storage->get(self::KEY_USER_TOKEN);

            if ($cached !== null && $cached !== '') {
                return $cached;
            }
        }

        if ($this->login === null || $this->login === '' || $this->password === null || $this->password === '') {
            throw new StarlineAuthException(
                'Для получения токена пользователя необходимо передать логин и пароль StarLine в конструктор.'
            );
        }

        $appToken = $this->getAppToken();

        $response = $this->http->postForm(
            self::BASE_ID_URL . '/apiV3/user/login?token=' . urlencode($appToken),
            [
                'login' => $this->login,
                'pass' => sha1($this->password),
            ]
        );

        $data = $this->decodeIdResponse($response, 'user/login');
        $token = Arr::get($data, 'desc.user_token');

        if (!is_string($token) || $token === '') {
            throw new StarlineAuthException('user/login: не получен desc.user_token.');
        }

        $this->storage->set(self::KEY_USER_TOKEN, $token);

        return $token;
    }

    /**
     * Шаг 4. Получить slnet-токен (cookie) — финальный ключ для API-запросов.
     *
     * Выполняет полную цепочку, если токенов ещё нет. Если user_token
     * протух и auth.slid не отдал cookie — один раз перевыпускает
     * user_token принудительно.
     *
     * @param bool $force Полная переавторизация.
     * @throws StarlineAuthException
     */
    public function getSlnetToken(bool $force = false): string
    {
        if (!$force) {
            $cached = $this->storage->get(self::KEY_SLNET);

            if ($cached !== null && $cached !== '') {
                return $cached;
            }
        }

        $slnet = $this->exchangeUserToken($this->getUserToken());

        if ($slnet === null) {
            $slnet = $this->exchangeUserToken($this->getUserToken(true));
        }

        if ($slnet === null) {
            throw new StarlineAuthException('auth.slid не вернул cookie slnet. Проверьте учётные данные.');
        }

        $this->storage->set(self::KEY_SLNET, $slnet);

        return $slnet;
    }

    /**
     * Определить user_id текущего пользователя.
     *
     * Стратегии (по убыванию приоритета):
     * 1) кэш;
     * 2) GET /json/v1/user_info (если поддерживается текущей версией API);
     * 3) разбор user_token формата "<hash>:<user_id>";
     * 4) явная установка через {@see setUserId()}.
     *
     * @throws StarlineAuthException
     */
    public function getUserId(bool $force = false): int
    {
        if (!$force) {
            $cached = $this->storage->get(self::KEY_USER_ID);

            if ($cached !== null && ctype_digit($cached)) {
                return (int) $cached;
            }
        }

        $slnet = $this->getSlnetToken();

        $response = $this->http->get(self::BASE_API_URL . '/json/v1/user_info', [], [
            'Cookie' => 'slnet=' . $slnet,
        ]);

        if ($response->statusCode === 200) {
            $data = $response->json();
            $id = is_array($data) ? Arr::get($data, 'desc.id') : null;

            if (is_numeric($id)) {
                $this->storage->set(self::KEY_USER_ID, (string) (int) $id);

                return (int) $id;
            }
        }

        $userToken = $this->getUserToken();

        if (str_contains($userToken, ':')) {
            $suffix = substr($userToken, strrpos($userToken, ':') + 1);

            if (ctype_digit($suffix)) {
                $this->storage->set(self::KEY_USER_ID, $suffix);

                return (int) $suffix;
            }
        }

        throw new StarlineAuthException(
            'Не удалось автоматически определить user_id. Передайте его через StarlineApi::setUserId().'
        );
    }

    /**
     * Явно задать user_id (например, если он известен из кабинета StarLine).
     */
    public function setUserId(int $userId): void
    {
        $this->storage->set(self::KEY_USER_ID, (string) $userId);
    }

    /**
     * Сбросить все закэшированные токены (используется при переавторизации).
     */
    public function reset(): void
    {
        $this->storage->delete(self::KEY_APP_TOKEN);
        $this->storage->delete(self::KEY_USER_TOKEN);
        $this->storage->delete(self::KEY_SLNET);
        $this->storage->delete(self::KEY_USER_ID);
    }

    /**
     * Обменять user_token на slnet-cookie.
     *
     * @return string|null slnet или null, если сервер не отдал cookie.
     */
    private function exchangeUserToken(string $userToken): ?string
    {
        $response = $this->http->postJson(self::BASE_API_URL . '/json/v2/auth.slid', [
            'slid_token' => $userToken,
        ]);

        // Ответ auth.slid содержит user_id на верхнем уровне JSON.
        $data = $response->json();

        if (is_array($data)) {
            $userId = Arr::get($data, 'user_id');

            if (is_numeric($userId)) {
                $this->storage->set(self::KEY_USER_ID, (string) (int) $userId);
            }
        }

        return $response->cookie('slnet');
    }

    /**
     * Разобрать ответ id.starline.ru и проверить конверт {"state": 1, ...}.
     *
     * @return array<mixed>
     * @throws StarlineAuthException
     */
    private function decodeIdResponse(Response $response, string $context): array
    {
        if ($response->statusCode >= 400) {
            throw new StarlineAuthException(sprintf(
                '%s: HTTP %d: %s',
                $context,
                $response->statusCode,
                mb_substr($response->body, 0, 200)
            ));
        }

        $data = $response->json();

        if ($data === null) {
            throw new StarlineAuthException(sprintf(
                '%s: некорректный JSON: %s',
                $context,
                mb_substr($response->body, 0, 200)
            ));
        }

        if (isset($data['state']) && (int) $data['state'] !== 1) {
            $message = Arr::get($data, 'desc.message', 'неизвестная ошибка');

            throw new StarlineAuthException(sprintf('%s: %s', $context, (string) $message));
        }

        return $data;
    }
}
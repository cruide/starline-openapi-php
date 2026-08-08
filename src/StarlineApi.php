<?php namespace Cruide\StarlineApi;

use Cruide\StarlineApi\Api\DeviceApi;
use Cruide\StarlineApi\Api\UserApi;
use Cruide\StarlineApi\Auth\Authenticator;
use Cruide\StarlineApi\Auth\InMemoryTokenStorage;
use Cruide\StarlineApi\Auth\TokenStorageInterface;
use Cruide\StarlineApi\Exceptions\StarlineApiException;
use Cruide\StarlineApi\Exceptions\StarlineAuthException;
use Cruide\StarlineApi\Exceptions\StarlineException;
use Cruide\StarlineApi\Http\CurlHttpClient;
use Cruide\StarlineApi\Http\HttpClientInterface;
use Cruide\StarlineApi\Http\Response;
use Cruide\StarlineApi\Support\Arr;

/**
 * Главная точка входа в API StarLine.
 *
 * @author Alexander Tischenko (http://alex-tisch.ru)
 *
 * Пример использования:
 *
 *     $api = new StarlineApi($appId, $appSecret, $login, $password);
 *     $api->authenticate();
 *
 *     $devices = $api->user()->devices();
 *     $state = $api->devices()->state($devices[0]->id());
 *
 *     echo $state->isEngineRunning() ? 'двигатель работает' : 'двигатель заглушен';
 *
 * @see https://developer.starline.ru/
 */
final class StarlineApi
{
    private HttpClientInterface $http;
    private Authenticator $auth;
    private ?UserApi $userApi = null;
    private ?DeviceApi $deviceApi = null;

    /**
     * @param int|string $appId ID приложения из кабинета разработчика StarLine.
     * @param string $appSecret Секретный ключ приложения.
     * @param string|null $login Логин (email) аккаунта StarLine.
     * @param string|null $password Пароль аккаунта StarLine.
     * @param HttpClientInterface|null $httpClient HTTP-клиент (по умолчанию cURL).
     * @param TokenStorageInterface|null $tokenStorage Хранилище токенов (по умолчанию — память процесса).
     */
    public function __construct(
        int|string $appId,
        string $appSecret,
        ?string $login = null,
        ?string $password = null,
        ?HttpClientInterface $httpClient = null,
        ?TokenStorageInterface $tokenStorage = null
    ) {
        $this->http = $httpClient ?? new CurlHttpClient();
        $this->auth = new Authenticator(
            $this->http,
            $tokenStorage ?? new InMemoryTokenStorage(),
            $appId,
            $appSecret,
            $login,
            $password
        );
    }

    /**
     * API пользователя.
     */
    public function user(): UserApi
    {
        return $this->userApi ??= new UserApi($this);
    }

    /**
     * API устройств.
     */
    public function devices(): DeviceApi
    {
        return $this->deviceApi ??= new DeviceApi($this);
    }

    /**
     * Доступ к авторизатору (токены, user_id, тонкая настройка).
     */
    public function authenticator(): Authenticator
    {
        return $this->auth;
    }

    /**
     * Явно задать user_id (если автоопределение не сработало).
     */
    public function setUserId(int $userId): void
    {
        $this->auth->setUserId($userId);
    }

    /**
     * Выполнить полную SLID-авторизацию и закэшировать токены.
     *
     * Вызывать необязательно: авторизация лениво выполняется
     * при первом API-запросе.
     *
     * @param bool $force Принудительная переавторизация.
     * @throws StarlineAuthException
     */
    public function authenticate(bool $force = false): void
    {
        $this->auth->getSlnetToken($force);
    }

    /**
     * GET-запрос к developer.starline.ru с авторизацией slnet.
     *
     * @param string $path Путь (например "/json/v3/device/123/data") либо полный URL.
     * @param array<string, scalar|null> $query Query-параметры.
     * @return array<mixed> Содержимое desc ответа.
     */
    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, $query);
    }

    /**
     * POST-запрос с JSON-телом.
     *
     * @param string $path Путь либо полный URL.
     * @param array<mixed> $json Тело запроса.
     * @return array<mixed> Содержимое desc ответа.
     */
    public function post(string $path, array $json = []): array
    {
        return $this->request('POST', $path, [], $json);
    }

    /**
     * Универсальный запрос к API с автоматической переавторизацией.
     *
     * Если сервер вернул 401/403 (slnet протух), сессия сбрасывается
     * и запрос повторяется один раз с новой цепочкой токенов.
     * Подходит для обращения к любому эндпоинту, даже если для него
     * нет обёртки в библиотеке.
     *
     * @param string $method HTTP-метод.
     * @param string $path Путь либо полный URL.
     * @param array<string, scalar|null> $query Query-параметры.
     * @param array<mixed>|null $json JSON-тело (для POST).
     * @return array<mixed> Содержимое desc ответа.
     * @throws StarlineAuthException
     * @throws StarlineApiException
     */
    public function request(string $method, string $path, array $query = [], ?array $json = null): array
    {
        try {
            return $this->doRequest($method, $path, $query, $json);
        } catch (StarlineAuthException) {
            // Токены протухли: сбрасываем сессию и повторяем один раз.
            $this->auth->reset();

            return $this->doRequest($method, $path, $query, $json);
        }
    }

    /**
     * Один запрос без ретрая.
     *
     * @param array<string, scalar|null> $query
     * @param array<mixed>|null $json
     * @return array<mixed>
     */
    private function doRequest(string $method, string $path, array $query, ?array $json): array
    {
        $slnet = $this->auth->getSlnetToken();
        $url = $this->buildUrl($path);
        $headers = ['Cookie' => 'slnet=' . $slnet];

        $method = strtoupper($method);

        $response = match ($method) {
            'GET' => $this->http->get($url, $query, $headers),
            'POST' => $json !== null
                ? $this->http->postJson($url, $json, $headers)
                : $this->http->postForm($url, $query, $headers),
            default => throw new StarlineException(sprintf('HTTP-метод "%s" не поддерживается.', $method)),
        };

        return $this->unwrap($response, $path);
    }

    /**
     * Разобрать ответ API.
     *
     * Поддерживаются оба варианта конвертов:
     * - {"state": 1, "desc": {...}}                — id.starline.ru;
     * - {"code": 200, "codestring": "OK", "desc": {...}} — developer.starline.ru.
     *
     * @return array<mixed> Содержимое ключа desc.
     * @throws StarlineAuthException
     * @throws StarlineApiException
     */
    private function unwrap(Response $response, string $context): array
    {
        if ($response->statusCode === 401 || $response->statusCode === 403) {
            throw new StarlineAuthException(sprintf(
                'Авторизация отклонена (HTTP %d): %s',
                $response->statusCode,
                $context
            ));
        }

        if ($response->statusCode >= 400) {
            throw new StarlineApiException(
                sprintf('Ошибка HTTP %d для %s: %s', $response->statusCode, $context, $this->shortBody($response)),
                $response->statusCode
            );
        }

        if ($response->body === '') {
            return [];
        }

        $data = $response->json();

        if ($data === null) {
            throw new StarlineApiException(sprintf(
                'Некорректный JSON в ответе %s: %s',
                $context,
                $this->shortBody($response)
            ));
        }

        if (isset($data['state']) && (int) $data['state'] !== 1) {
            $message = Arr::get($data, 'desc.message', 'неизвестная ошибка');

            throw new StarlineApiException(sprintf('%s: %s', $context, (string) $message), 0, $data);
        }

        if (isset($data['code']) && is_numeric($data['code'])) {
            $code = (int) $data['code'];

            if ($code === 401 || $code === 403) {
                throw new StarlineAuthException(sprintf('Авторизация отклонена (код %d): %s', $code, $context));
            }

            if ($code >= 400) {
                $message = Arr::get($data, 'codestring', Arr::get($data, 'desc.message', 'неизвестная ошибка'));

                throw new StarlineApiException(sprintf('%s: [%d] %s', $context, $code, (string) $message), $code, $data);
            }
        }

        $desc = $data['desc'] ?? $data;

        return is_array($desc) ? $desc : $data;
    }

    /**
     * Собрать полный URL из пути.
     */
    private function buildUrl(string $path): string
    {
        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        return Authenticator::BASE_API_URL . '/' . ltrim($path, '/');
    }

    private function shortBody(Response $response): string
    {
        return mb_substr($response->body, 0, 200);
    }
}
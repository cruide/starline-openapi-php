<?php namespace Cruide\StarlineApi\Auth;

use Cruide\StarlineApi\Exceptions\StarlineAuthCaptchaException;
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

    private ?string $captchaSid = null;
    private ?string $captchaCode = null;
    private ?string $smsCode = null;

    private ?string $lastCaptchaSid = null;
    private ?string $lastCaptchaImg = null;

    private ?OcrInterface $ocr = null;
    private bool $autoCaptchaRetryDone = false;

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
     * Если установлен OCR-движок ({@see setOcr}), капча распознаётся
     * автоматически и логин повторяется. Исключение выбрасывается только
     * если автораспознавание не настроено, не удалось или капча неверна.
     *
     * @param bool $force Принудительно перелогиниться.
     * @throws StarlineAuthException
     * @throws StarlineAuthCaptchaException
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
        $this->autoCaptchaRetryDone = false;

        $token = $this->performLogin($appToken);

        $this->storage->set(self::KEY_USER_TOKEN, $token);

        return $token;
    }

    /**
     * Одна попытка user/login с автоматическим ретраем капчи.
     */
    private function performLogin(string $appToken): string
    {
        $response = $this->http->postForm(
            self::BASE_ID_URL . '/apiV3/user/login',
            $this->buildLoginData(),
            ['token' => $appToken]
        );

        // Попытка автораспознавания капчи (один раз)
        if ($this->ocr !== null && !$this->autoCaptchaRetryDone) {
            $captchaInfo = $this->detectCaptcha($response);

            if ($captchaInfo !== null) {
                $code = $this->solveCaptcha($captchaInfo['img']);

                if ($code !== null && $code !== '') {
                    $this->setCaptchaParams($captchaInfo['sid'], $code);
                    $this->autoCaptchaRetryDone = true;

                    return $this->performLogin($appToken);
                }
            }
        }

        // Если капча/SMS остались — исключение
        $this->checkForCaptchaOrSms($response);

        $data = $this->decodeIdResponse($response, 'user/login');
        $token = Arr::get($data, 'desc.user_token');

        if (!is_string($token) || $token === '') {
            throw new StarlineAuthException('user/login: не получен desc.user_token.');
        }

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
     * Авторизация через готовый StarLineID-токен (в обход SLID-цепочки).
     *
     * Токен формата "<hash>:<user_id>" получается на сервере StarLineID
     * (OAuth) и сразу обменивается на slnet через auth.slid.
     *
     * Не требует appId/appSecret/login/password — достаточно иметь
     * HTTP-клиент и хранилище токенов.
     *
     * @param string $slidToken StarLineID-токен (user_token).
     * @throws StarlineAuthException
     */
    public function authenticateWithSlidToken(string $slidToken): void
    {
        $slnet = $this->exchangeUserToken($slidToken);

        if ($slnet === null) {
            throw new StarlineAuthException('auth.slid не вернул cookie slnet. Проверьте slid_token.');
        }

        $this->storage->set(self::KEY_SLNET, $slnet);
        $this->storage->set(self::KEY_USER_TOKEN, $slidToken);
    }

    /**
     * Определить user_id текущего пользователя.
     *
     * Стратегии (по убыванию приоритета):
     * 1) кэш;
     * 2) разбор user_token формата "<hash>:<user_id>";
     * 3) явная установка через {@see setUserId()}.
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
     * Установить параметры капчи для повторного user/login.
     */
    public function setCaptchaParams(string $captchaSid, string $captchaCode): void
    {
        $this->captchaSid = $captchaSid;
        $this->captchaCode = $captchaCode;
    }

    /**
     * Установить SMS-код для повторного user/login.
     */
    public function setSmsCode(string $smsCode): void
    {
        $this->smsCode = $smsCode;
    }

    /**
     * Получить captchaSid последнего запроса капчи.
     */
    public function getLastCaptchaSid(): ?string
    {
        return $this->lastCaptchaSid;
    }

    /**
     * Получить URL изображения последней капчи.
     */
    public function getLastCaptchaImg(): ?string
    {
        return $this->lastCaptchaImg;
    }

    /**
     * Установить OCR-движок для автоматического распознавания капчи.
     *
     * Если задан, при запросе капчи библиотека сама скачает изображение,
     * распознает код и повторит логин. Исключение будет выброшено только
     * если авто-ретрай не помог.
     */
    public function setOcr(?OcrInterface $ocr): void
    {
        $this->ocr = $ocr;
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
        $this->captchaSid = null;
        $this->captchaCode = null;
        $this->smsCode = null;
        $this->lastCaptchaSid = null;
        $this->lastCaptchaImg = null;
        $this->autoCaptchaRetryDone = false;
    }

    /**
     * Собрать form-данные для user/login с учётом капчи и SMS.
     *
     * @return array<string, string>
     */
    private function buildLoginData(): array
    {
        $data = [
            'login' => $this->login,
            'pass' => sha1($this->password),
        ];

        if ($this->smsCode !== null && $this->smsCode !== '') {
            $data['smsCode'] = $this->smsCode;
        }

        if ($this->captchaSid !== null && $this->captchaSid !== ''
            && $this->captchaCode !== null && $this->captchaCode !== '') {
            $data['captchaSid'] = $this->captchaSid;
            $data['captchaCode'] = $this->captchaCode;
        }

        return $data;
    }

    /**
     * Извлечь информацию о капче из ответа user/login (без исключения).
     *
     * @return array{sid: string, img: string}|null
     */
    private function detectCaptcha(Response $response): ?array
    {
        $raw = $response->json();

        if (!is_array($raw) || !isset($raw['state']) || (int) $raw['state'] === 1) {
            return null;
        }

        if ($response->statusCode >= 400) {
            return null;
        }

        $desc = $raw['desc'] ?? [];

        if (is_array($desc) && isset($desc['captchaSid'], $desc['captchaImg'])) {
            $this->lastCaptchaSid = $desc['captchaSid'];
            $this->lastCaptchaImg = $desc['captchaImg'];

            return [
                'sid' => $desc['captchaSid'],
                'img' => $desc['captchaImg'],
            ];
        }

        return null;
    }

    /**
     * Скачать изображение капчи и распознать код.
     */
    private function solveCaptcha(string $imgUrl): ?string
    {
        try {
            $response = $this->http->get($imgUrl);
        } catch (\Throwable) {
            return null;
        }

        if ($response->body === '') {
            return null;
        }

        return $this->ocr->decode($response->body);
    }

    /**
     * Проверить ответ user/login на запрос капчи/SMS.
     *
     * @throws StarlineAuthCaptchaException
     */
    private function checkForCaptchaOrSms(Response $response): void
    {
        $raw = $response->json();

        if (!is_array($raw) || !isset($raw['state']) || (int) $raw['state'] === 1) {
            return;
        }

        // При успешном HTTP-ответе state в {0, 2} может быть капчой/SMS/другим
        if ($response->statusCode >= 400) {
            return;
        }

        $desc = $raw['desc'] ?? [];

        if (is_array($desc) && isset($desc['captchaSid'])) {
            $this->lastCaptchaSid = $desc['captchaSid'];
            $this->lastCaptchaImg = $desc['captchaImg'] ?? null;

            throw new StarlineAuthCaptchaException(
                'user/login: требуется ввод капчи. Получите изображение через getCaptchaImg(), '
                . 'установите капчу через setCaptchaParams() и повторите попытку.',
                $desc['captchaSid'] ?? null,
                $desc['captchaImg'] ?? null,
            );
        }

        if (is_array($desc) && isset($desc['phone'])) {
            throw new StarlineAuthCaptchaException(
                'user/login: требуется SMS-подтверждение. '
                . 'Установите код через setSmsCode() и повторите попытку.',
                phone: $desc['phone'] ?? null,
            );
        }
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
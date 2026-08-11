# Starline OpenApi for PHP

> Автор: [Alexander Tischenko](http://alex-tisch.ru)

PHP-библиотека (клиент) для [StarLine OpenAPI](https://developer.starline.ru/) —
телематика охранных комплексов StarLine: состояние автомобиля, дистанционные
команды, события и история.

- PHP **>= 8.0**, только `ext-curl` и `ext-json` — без внешних зависимостей;
- полная SLID-авторизация с кэшированием токенов и автопереавторизацией при 401;
- поддержка капчи и SMS-подтверждения при логине;
- автоматическое распознавание капчи (чистый PHP GD либо Tesseract OCR);
- авторизация через готовый StarLineID-токен (в обход SLID-цепочки);
- типизированные модели (`UserInfo`, `Device`, `DeviceState`);
- универсальный `request()` для любого эндпоинта из документации;
- HTTP-клиент подменяемый (интерфейс) — легко подключить Guzzle/PSR-18.

> Библиотека не аффилирована с НПО «СтарЛайн». Ответственность за использование
> API — на вас (см. условия использования StarLine).

## Установка

```bash
composer require cruide/starline-openapi-php

```

или локально через `repositories` в composer.json:

```json
{
    "repositories": [
        { "type": "path", "url": "../cruide/starline-openapi-php" }
    ]
}
```

## Где взять App ID и Secret Key

1. Зарегистрируйтесь в кабинете разработчика: https://developer.starline.ru
2. Создайте приложение — получите **App ID** и **Secret Key**.

## Схема авторизации (SLID)

| Шаг | Запрос | Параметры | Результат |
|-----|--------|-----------|-----------|
| 1 | `GET id.starline.ru/apiV3/application/getCode` | `appId`, `secret=md5(appSecret)` | код приложения |
| 2 | `GET id.starline.ru/apiV3/application/getToken` | `appId`, `secret=md5(appSecret+code)` | токен приложения |
| 3 | `POST id.starline.ru/apiV3/user/login` | Header: `token: <app_token>`, form: `login`, `pass=sha1(password)` | `user_token` |
| 4 | `POST developer.starline.ru/json/v2/auth.slid` | JSON: `{"slid_token":"<user_token>"}` | cookie `slnet` + `user_id` |

Все дальнейшие запросы идут с заголовком `Cookie: slnet=<токен>`.
Вся цепочка выполняется библиотекой автоматически и кэшируется
в `TokenStorageInterface`.

## Быстрый старт

### SLID-авторизация

```php
use Cruide\StarlineApi\StarlineApi;
use Cruide\StarlineApi\Auth\FileTokenStorage;
use Cruide\StarlineApi\Auth\GdOcr;

$api = new StarlineApi(
    appId: 123456,
    appSecret: 'ваш-secret',
    login: 'user@example.com',
    password: 'пароль',
    tokenStorage: new FileTokenStorage('/var/tmp/starline-tokens.json'),
);

// Автоматическое распознавание капчи (чистый PHP, только ext-gd)
$api->setOcr(new GdOcr());

$api->authenticate();

foreach ($api->user()->devices() as $device) {
    $state = $api->devices()->state($device->id());

    echo $device->alias(), ': ', $state->isArmed() ? 'охрана' : 'снято', PHP_EOL;
}
```

### StarLineID-токен

```php
$api = new StarlineApi(appId: 123, appSecret: '***');
$api->authenticateWithSlidToken('hash:user_id');
```

### Ручная капча / SMS

```php
use Cruide\StarlineApi\Exceptions\StarlineAuthCaptchaException;

try {
    $api->authenticate();
} catch (StarlineAuthCaptchaException $e) {
    if ($e->isCaptchaRequired()) {
        // Показать пользователю: $e->getCaptchaImg()
        $api->authenticateWithCaptcha($e->getCaptchaSid(), $userInput);
    } elseif ($e->isSmsRequired()) {
        // SMS на номер: $e->getPhone()
        $api->authenticateWithSms($smsCode);
    }
}
```

### Авто-капча + Tesseract (опционально, требует `tesseract-ocr`)

```php
use Cruide\StarlineApi\Auth\TesseractOcr;

$api->setOcr(new TesseractOcr(lang: 'eng', psm: '8'));
$api->authenticate();  // капча решится сама
```

## Основные методы

| Метод | Описание |
|-------|----------|
| `$api->authenticate(bool $force = false)` | Полная SLID-авторизация |
| `$api->authenticateWithSlidToken(string $token)` | Авторизация через StarLineID-токен (в обход SLID) |
| `$api->authenticateWithCaptcha(string $sid, string $code)` | Повторная авторизация с капчей |
| `$api->authenticateWithSms(string $code)` | Повторная авторизация с SMS-кодом |
| `$api->authenticateWithCaptchaAuto(OcrInterface $ocr)` | Авто-капча: скачивание, OCR, повтор |
| `$api->setOcr(OcrInterface $ocr)` | Подключить автораспознавание капчи |
| `$api->user()->id()` | user_id текущего пользователя |
| `$api->user()->info()` | `UserInfo` (профиль + устройства) |
| `$api->devices()->list()` | Список `Device` |
| `$api->devices()->state($deviceId)` | `DeviceState` (`/json/v3/device/{id}/data`) |
| `$api->devices()->position($deviceId)` | Последнее местоположение (`/json/v1/device/{id}/position`) |
| `$api->devices()->setParam($deviceId, $params)` | Команда (`/json/v1/device/{id}/set_param`) |
| `$api->devices()->arm/disarm/startEngine/stopEngine($deviceId)` | Типовые команды |
| `$api->devices()->events($deviceId, $periodStart, $periodEnd)` | События за период |
| `$api->devices()->history($deviceId, $from, $to)` | GPS-история за период |
| `$api->devices()->ways($deviceId, $begin, $end, $extra)` | Трек (координаты, пробег, время в движении) |
| `$api->get($path, $query)` / `$api->post($path, $json)` | Универсальные запросы |

## Хранение токенов

По умолчанию токены живут только в памяти процесса. Для веба/демонов
реализуйте `TokenStorageInterface`, например на кэше Laravel:

```php
use Illuminate\Support\Facades\Cache;
use Cruide\StarlineApi\Auth\TokenStorageInterface;

final class CacheTokenStorage implements TokenStorageInterface
{
    public function get(string $key): ?string
    {
        return Cache::get($key);
    }

    public function set(string $key, string $value, ?int $ttl = null): void
    {
        Cache::put($key, $value, $ttl ?? 3600 * 24 * 30);
    }

    public function delete(string $key): void
    {
        Cache::forget($key);
    }
}
```

## Обработка ошибок

| Исключение | Когда |
|------------|-------|
| `StarlineAuthException` | неверные App ID/Secret/логин/пароль, истёкшие токены (после одной автоповторной попытки) |
| `StarlineAuthCaptchaException` | запрос капчи или SMS-кода (если OCR не настроен или не справился) |
| `StarlineApiException` | ошибки API (HTTP >= 400 или `state`/`code` != успех); `getRaw()` — сырой ответ |
| `StarlineHttpException` | транспортные ошибки cURL |

## Примечания

- `md5`/`sha1` в цепочке авторизации — требование протокола StarLine.
- Если `user_id` не определился автоматически, задайте его явно:
  `$api->setUserId(123456);`
- Точные форматы тел запросов команд и параметров событий/истории
  сверяйте с актуальным Swagger на https://developer.starline.ru —
  для нестандартных запросов используйте `$api->request()`.

## Тесты

```bash
composer install
composer test
```

## Лицензия

MIT
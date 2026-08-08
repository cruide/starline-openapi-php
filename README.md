# Starline OpenApi for PHP

> Автор: [Alexander Tischenko](http://alex-tisch.ru)

PHP-библиотека (клиент) для [StarLine OpenAPI](https://developer.starline.ru/) —
телематика охранных комплексов StarLine: состояние автомобиля, дистанционные
команды, события и история.

- PHP **>= 8.0**, только `ext-curl` и `ext-json` — без внешних зависимостей;
- полная SLID-авторизация с кэшированием токенов и автопереавторизацией при 401;
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
        { "type": "path", "url": "../starline-api" }
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
| 3 | `POST id.starline.ru/apiV3/user/login` | `?token=<app_token>`, form: `login`, `pass=sha1(password)` | `user_token` |
| 4 | `POST developer.starline.ru/json/v2/auth.slid` | JSON: `{"slid_token":"<user_token>"}` | cookie `slnet` + `user_id` |

Все дальнейшие запросы идут с заголовком `Cookie: slnet=<токен>`.
Вся цепочка выполняется библиотекой автоматически и кэшируется
в `TokenStorageInterface`.

## Быстрый старт

```php
use StarlineApi\StarlineApi;
use StarlineApi\Auth\FileTokenStorage;

$api = new StarlineApi(
    appId: 123456,
    appSecret: 'ваш-secret',
    login: 'user@example.com',
    password: 'пароль',
    tokenStorage: new FileTokenStorage('/var/tmp/starline-tokens.json'),
);

$api->authenticate();

foreach ($api->user()->devices() as $device) {
    $state = $api->devices()->state($device->id());

    echo $device->alias(), ': ', $state->isArmed() ? 'охрана' : 'снято', PHP_EOL;
}

// Команды:
// $api->devices()->startEngine($deviceId);
// $api->devices()->arm($deviceId);

// Произвольный эндпоинт:
// $data = $api->get('/json/v3/device/' . $deviceId . '/data');
```

## Основные методы

| Метод | Описание |
|-------|----------|
| `$api->authenticate(bool $force = false)` | Полная SLID-авторизация |
| `$api->user()->id()` | user_id текущего пользователя |
| `$api->user()->info()` | `UserInfo` (профиль + устройства) |
| `$api->devices()->list()` | Список `Device` |
| `$api->devices()->state($deviceId)` | `DeviceState` (`/json/v3/device/{id}/data`) |
| `$api->devices()->setParam($deviceId, $params)` | Команда (`/json/v1/device/{id}/set_param`) |
| `$api->devices()->arm/disarm/startEngine/stopEngine($deviceId)` | Типовые команды |
| `$api->devices()->events($deviceId, $from, $to)` | События за период |
| `$api->devices()->history($deviceId, $from, $to)` | GPS-история за период |
| `$api->get($path, $query)` / `$api->post($path, $json)` | Универсальные запросы |

## Хранение токенов

По умолчанию токены живут только в памяти процесса. Для веба/демонов
реализуйте `TokenStorageInterface`, например на кэше Laravel:

```php
use Illuminate\Support\Facades\Cache;
use StarlineApi\Auth\TokenStorageInterface;

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
# StarLine OpenAPI — PHP Client

PHP-библиотека для [StarLine OpenAPI](https://developer.starline.ru/) —
телематика охранных комплексов StarLine.

> Автор: [Alexander Tischenko](http://alex-tisch.ru)

---

## Оглавление

- [Установка](#установка)
- [Быстрый старт](#быстрый-старт)
- [Схема авторизации](#схема-авторизации)
- [API-методы](#api-методы)
  - [StarlineApi](#starlineapi)
  - [UserApi](#userapi)
  - [DeviceApi](#deviceapi)
- [Модели данных](#модели-данных)
  - [Device](#device)
  - [DeviceState](#devicestate)
  - [UserInfo](#userinfo)
- [Хранение токенов](#хранение-токенов)
- [Обработка ошибок](#обработка-ошибок)
- [HTTP-клиент](#http-клиент)
- [Примеры использования](EXAMPLES.md)

---

## Установка

```bash
composer require cruide/starline-openapi-php
```

**Требования:** PHP >= 8.0, `ext-curl`, `ext-json`. Внешних зависимостей нет.

## Быстрый старт

```php
use StarlineApi\StarlineApi;

$api = new StarlineApi(
    appId: 123456,
    appSecret: '***',
    login: 'user@example.com',
    password: '***',
);

foreach ($api->user()->devices() as $device) {
    $state = $api->devices()->state($device->id());
    echo $device->alias(), ': ', $state->isArmed() ? 'охрана' : 'снято', PHP_EOL;
}
```

Авторизация выполняется **лениво** при первом обращении к API —
явный вызов `$api->authenticate()` не обязателен.

## Схема авторизации

Библиотека реализует 4-шаговую SLID-авторизацию:

| Шаг | Сервер | Метод | Эндпоинт | Результат |
|-----|--------|-------|----------|-----------|
| 1 | `id.starline.ru` | GET | `/apiV3/application/getCode` | `code` (одноразовый код приложения) |
| 2 | `id.starline.ru` | GET | `/apiV3/application/getToken` | `token` (токен приложения, живёт 4 часа) |
| 3 | `id.starline.ru` | POST | `/apiV3/user/login` | `user_token` (Slid-токен, живёт 1 год) |
| 4 | `developer.starline.ru` | POST | `/json/v2/auth.slid` | `slnet` cookie (живёт 24 часа) |

Все токены кэшируются в `TokenStorageInterface` и переиспользуются.
При получении **401/403** библиотека сбрасывает кэш и повторяет авторизацию **один раз**.

## API-методы

### StarlineApi

Главная точка входа. Создаётся один раз на всё приложение.

```php
$api = new StarlineApi(
    int|string $appId,
    string $appSecret,
    ?string $login = null,
    ?string $password = null,
    ?HttpClientInterface $httpClient = null,
    ?TokenStorageInterface $tokenStorage = null,
);
```

#### Публичные методы

| Метод | Возврат | Описание |
|-------|---------|----------|
| `authenticate(bool $force = false)` | `void` | Принудительная SLID-авторизация |
| `user()` | `UserApi` | Доступ к методам пользователя |
| `devices()` | `DeviceApi` | Доступ к методам устройств |
| `setUserId(int $userId)` | `void` | Явно задать `user_id` (если автоопределение не сработало) |
| `get(string $path, array $query = [])` | `array` | GET-запрос к API (возвращает содержимое `desc`) |
| `post(string $path, array $json = [])` | `array` | POST-запрос с JSON-телом (возвращает содержимое `desc`) |
| `request(string $method, string $path, array $query = [], ?array $json = null)` | `array` | Универсальный запрос (любой эндпоинт) |

---

### UserApi

Доступ через `$api->user()`.

| Метод | Возврат | Описание |
|-------|---------|----------|
| `id()` | `int` | `user_id` текущего пользователя |
| `info()` | `UserInfo` | Профиль пользователя + список устройств |
| `devices()` | `Device[]` | Массив устройств (`Device`) |

---

### DeviceApi

Доступ через `$api->devices()`.

| Метод | Возврат | Описание |
|-------|---------|----------|
| `list()` | `Device[]` | Список устройств пользователя |
| `state(int\|string $deviceId)` | `DeviceState` | Текущее состояние устройства |
| `setParam(int\|string $deviceId, array $params)` | `array` | Отправка произвольной команды |
| `arm(int\|string $deviceId)` | `array` | Постановка на охрану |
| `disarm(int\|string $deviceId)` | `array` | Снятие с охраны |
| `startEngine(int\|string $deviceId)` | `array` | Дистанционный запуск двигателя |
| `stopEngine(int\|string $deviceId)` | `array` | Остановка двигателя |
| `events(int\|string $deviceId, int $tsFrom, int $tsTo, array $extra = [])` | `array` | События за период |
| `history(int\|string $deviceId, int $from, int $to, array $extra = [])` | `array` | GPS-история за период |

---

## Модели данных

### Device

Неизменяемый DTO устройства из `user_info`.

| Геттер | Тип | Источник в JSON |
|--------|-----|-----------------|
| `id()` | `?int` | `device_id` или `id` |
| `type()` | `?string` | `device_type` или `type` |
| `alias()` | `?string` | `alias` или `name` |
| `imei()` | `?string` | `imei` |
| `isOnline()` | `?bool` | `online` |
| `raw()` | `array` | Исходный массив |

---

### DeviceState

Неизменяемый DTO состояния из `/json/v3/device/{id}/data`.

Структура ответа зависит от прошивки устройства, поэтому все геттеры
возвращают `null`, если поле отсутствует. Для доступа к полям,
не покрытым геттерами, используйте `raw()`.

| Геттер | Тип | Описание |
|--------|-----|----------|
| `deviceId()` | `?int` | ID устройства |
| `isArmed()` | `?bool` | Охрана включена |
| `isEngineRunning()` | `?bool` | Двигатель работает |
| `interiorTemperature()` | `?float` | Температура салона, °C |
| `engineTemperature()` | `?float` | Температура двигателя, °C |
| `batteryVoltage()` | `?float` | Напряжение АКБ, В |
| `gsmBalance()` | `?float` | Баланс SIM-карты |
| `latitude()` | `?float` | Широта |
| `longitude()` | `?float` | Долгота |
| `mileage()` | `?int` | Пробег, км |
| `updatedAt()` | `?int` | Время обновления (unixtime) |
| `raw()` | `array` | Исходный массив |

---

### UserInfo

Неизменяемый DTO профиля из `/json/v1/user/{id}/user_info`.

| Геттер | Тип | Описание |
|--------|-----|----------|
| `id()` | `?int` | ID пользователя |
| `name()` | `?string` | Имя |
| `email()` | `?string` | Email |
| `devices()` | `Device[]` | Устройства пользователя |
| `raw()` | `array` | Исходный массив |

---

## Хранение токенов

### InMemoryTokenStorage (по умолчанию)

Токены живут только в памяти процесса. Подходит для одноразовых CLI-скриптов.
При каждом запуске выполняется полная авторизация.

### FileTokenStorage

Токены сохраняются в JSON-файл. Подходит для cron-задач.

```php
use StarlineApi\Auth\FileTokenStorage;

$api = new StarlineApi(
    appId: 123456,
    appSecret: '***',
    login: 'user@example.com',
    password: '***',
    tokenStorage: new FileTokenStorage('/var/tmp/starline-tokens.json'),
);
```

### Своё хранилище

Реализуйте `TokenStorageInterface` — три метода: `get()`, `set()`, `delete()`.

```php
use StarlineApi\Auth\TokenStorageInterface;

final class MyTokenStorage implements TokenStorageInterface
{
    public function get(string $key): ?string { /* ... */ }
    public function set(string $key, string $value, ?int $ttl = null): void { /* ... */ }
    public function delete(string $key): void { /* ... */ }
}
```

## Обработка ошибок

| Исключение | Когда |
|------------|-------|
| `StarlineException` | Базовое (наследует `\RuntimeException`) |
| `StarlineAuthException` | Ошибка авторизации: неверные креды, истёкшие токены (после одной повторной попытки) |
| `StarlineApiException` | Ошибка API: HTTP ≥ 400, `state ≠ 1`, `code ≥ 400`. Метод `getRaw()` возвращает сырой ответ |
| `StarlineHttpException` | Транспортная ошибка: недоступен сервер, таймаут cURL |

## HTTP-клиент

По умолчанию используется `CurlHttpClient`. Его можно заменить через внедрение
`HttpClientInterface` — например, на Guzzle-адаптер.

```php
use StarlineApi\Http\HttpClientInterface;

$api = new StarlineApi(
    appId: 123456,
    appSecret: '***',
    httpClient: new MyGuzzleAdapter(),
);
```

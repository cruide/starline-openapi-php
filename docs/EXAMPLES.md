# Примеры использования

## Содержание

- [Минимальный пример](#минимальный-пример)
- [Хранение токенов на диске](#хранение-токенов-на-диске)
- [Явная авторизация](#явная-авторизация)
- [Авторизация через StarLineID-токен](#авторизация-через-starlineid-токен)
- [Капча и SMS-подтверждение](#капча-и-sms-подтверждение)
- [Автоматическое распознавание капчи](#автоматическое-распознавание-капчи)
- [Обход всех устройств и их состояния](#обход-всех-устройств-и-их-состояния)
- [Команды устройству](#команды-устройству)
- [События и история](#события-и-история)
- [Трек устройства](#трек-устройства)
- [Произвольные запросы к API](#произвольные-запросы-к-api)
- [Явная установка user_id](#явная-установка-user_id)
- [Кастомное хранилище токенов (Laravel)](#кастомное-хранилище-токенов-laravel)
- [Кастомный HTTP-клиент (Guzzle)](#кастомный-http-клиент-guzzle)
- [Обработка ошибок](#обработка-ошибок)

---

## Минимальный пример

Самый простой сценарий: авторизация и вывод списка устройств.

```php
use Cruide\StarlineApi\StarlineApi;

$api = new StarlineApi(
    appId: 123456,
    appSecret: '***',
    login: 'user@example.com',
    password: '***',
);

$api->authenticate();

foreach ($api->user()->devices() as $device) {
    echo $device->id(), ' — ', ($device->alias() ?? 'без имени'), PHP_EOL;
}
```

---

## Хранение токенов на диске

Чтобы токены переживали перезапуск процесса (cron, демон):

```php
use Cruide\StarlineApi\Auth\FileTokenStorage;
use Cruide\StarlineApi\StarlineApi;

$api = new StarlineApi(
    appId: 123456,
    appSecret: '***',
    login: 'user@example.com',
    password: '***',
    tokenStorage: new FileTokenStorage(sys_get_temp_dir() . '/starline-tokens.json'),
);
```

---

## Явная авторизация

Обычно авторизация ленивая — первый вызов API всё сделает сам.
Но можно выполнить её принудительно:

```php
$api = new StarlineApi(/*...*/);

$api->authenticate();   // выполнить полную SLID-цепочку
$api->authenticate(true); // сбросить кэш и переавторизоваться заново
```

---

## Авторизация через StarLineID-токен

Если у вас уже есть StarLineID-токен (формата `hash:user_id`), полученный
через OAuth на сервере StarLineID, можно пропустить SLID-цепочку и сразу
обменять его на slnet:

```php
$api = new StarlineApi(
    appId: 123456,
    appSecret: '***',
    // Логин и пароль не нужны
);

$api->authenticateWithSlidToken('f6e706e17d41ce781b5166f09e782fd0:1663');

// Дальше — обычные запросы
$devices = $api->user()->devices();
```

---

## Капча и SMS-подтверждение

При подозрительной активности StarLine может запросить капчу или SMS-код.
Библиотека выбрасывает `StarlineAuthCaptchaException` с данными для повторной
авторизации:

```php
use Cruide\StarlineApi\Exceptions\StarlineAuthCaptchaException;

try {
    $api->authenticate();
} catch (StarlineAuthCaptchaException $e) {
    if ($e->isCaptchaRequired()) {
        // Получить URL картинки: $e->getCaptchaImg()
        // Получить sid: $e->getCaptchaSid()
        // Показать пользователю, получить код и повторить:
        $api->authenticateWithCaptcha($e->getCaptchaSid(), $codeFromUser);
    }

    if ($e->isSmsRequired()) {
        // SMS отправлен на номер: $e->getPhone()
        // Получить код от пользователя и повторить:
        $api->authenticateWithSms($smsCode);
    }
}
```

---

## Автоматическое распознавание капчи

### GdOcr — чистый PHP (только ext-gd)

```php
use Cruide\StarlineApi\Auth\GdOcr;

$api = new StarlineApi(/*...*/);
$api->setOcr(new GdOcr());
$api->authenticate();  // капча решится автоматически, исключения не будет
```

Настройка параметров бинаризации:

```php
$api->setOcr(new GdOcr(
    threshold: 128,        // 0 = авто (OTSU), иначе фиксированный порог 0..255
    minComponentSize: 10,  // минимальный размер символа (меньше — шум)
    minSymbolWidth: 5,     // минимальная ширина сегмента
));
```

### TesseractOcr — максимальная надёжность (требует `tesseract-ocr`)

```bash
# Установка
apt-get install tesseract-ocr   # Debian/Ubuntu
brew install tesseract          # macOS
```

```php
use Cruide\StarlineApi\Auth\TesseractOcr;

$api->setOcr(new TesseractOcr(
    lang: 'eng',    // язык
    psm: '8',       // режим сегментации (8 = одно слово)
    extraFlags: '-c tessedit_char_whitelist=0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ',
));
$api->authenticate();
```

### Ручной запуск авто-капчи

Если OCR не настроен через `setOcr()`, можно вызвать его явно после
перехвата исключения:

```php
try {
    $api->authenticate();
} catch (StarlineAuthCaptchaException $e) {
    $api->authenticateWithCaptchaAuto(new GdOcr());
}
```

### Свой OCR-движок

Реализуйте `OcrInterface::decode(string $imageData): ?string`:

```php
use Cruide\StarlineApi\Auth\OcrInterface;

final class MyOcr implements OcrInterface
{
    public function decode(string $imageData): ?string
    {
        // Отправить картинку в свой сервис распознавания
        return $recognizedText;
    }
}

$api->setOcr(new MyOcr());
```

---

## Обход всех устройств и их состояния

```php
$api = new StarlineApi(/*...*/);

foreach ($api->user()->devices() as $device) {
    echo "=== ", ($device->alias() ?? 'Устройство #' . $device->id()), " ===\n";

    $state = $api->devices()->state($device->id());

    echo 'Охрана:        ', $state->isArmed() ? 'да' : 'нет', "\n";
    echo 'Двигатель:     ', $state->isEngineRunning() ? 'работает' : 'остановлен', "\n";
    echo 'Салон:         ', ($state->interiorTemperature() ?? '?'), " °C\n";
    echo 'Двигатель:     ', ($state->engineTemperature() ?? '?'), " °C\n";
    echo 'АКБ:           ', ($state->batteryVoltage() ?? '?'), " В\n";
    echo 'Баланс SIM:    ', ($state->gsmBalance() ?? '?'), "\n";
    echo 'Пробег:        ', ($state->mileage() ?? '?'), " км\n";
    echo 'Координаты:    ', ($state->latitude() ?? '?'), ', ', ($state->longitude() ?? '?'), "\n";
    echo 'Обновлено:     ', $state->updatedAt()
        ? date('Y-m-d H:i:s', $state->updatedAt())
        : '?', "\n\n";
}
```

---

## Команды устройству

### Постановка / снятие с охраны

```php
$deviceId = 1234567890;

$api->devices()->arm($deviceId);    // поставить на охрану
$api->devices()->disarm($deviceId); // снять с охраны
```

### Запуск / остановка двигателя

```php
$api->devices()->startEngine($deviceId);
$api->devices()->stopEngine($deviceId);
```

### Произвольная команда через setParam

```php
$api->devices()->setParam($deviceId, [
    'security' => ['arm' => true],
    'engine'   => ['start' => true],
]);
```

---

## События и история

### События устройства за период

```php
$from = strtotime('2026-08-01 00:00:00');
$to   = strtotime('2026-08-08 00:00:00');

$events = $api->devices()->events($deviceId, $from, $to);

foreach ($events['events'] ?? [] as $event) {
    echo date('Y-m-d H:i:s', $event['ts'] ?? 0), ' — ', $event['event_type'] ?? '?', PHP_EOL;
}
```

### GPS-история

```php
$tracks = $api->devices()->history($deviceId, $from, $to);

foreach ($tracks['tracks'] ?? [] as $track) {
    echo 'Lat: ', $track['lat'] ?? '?', ' Lon: ', $track['lon'] ?? '?', PHP_EOL;
}
```

### Трек устройства

Метод `ways()` возвращает GPS-трек с координатами, пробегом и временем в движении.
В отличие от `history()`, также включает сегменты стоянок (STOP) и временну́ю зону (TZ).

```php
$begin = strtotime('2026-08-01 00:00:00');
$end   = strtotime('2026-08-08 00:00:00');

$track = $api->devices()->ways($deviceId, $begin, $end, [
    'split_way' => true,
    'dt_max' => 2,
]);

echo 'Пробег: ', $track['mileage'], " км\n";
echo 'Время в движении: ', $track['moving_time'], " с\n";

foreach ($track['way'] as $segment) {
    if ($segment['type'] === 'TRACK') {
        foreach ($segment['nodes'] as $node) {
            echo date('H:i:s', $node['t']), ' — ', $node['x'], ', ', $node['y'], "\n";
        }
    }
    if ($segment['type'] === 'STOP') {
        echo 'Стоянка с ', date('H:i:s', $segment['t']),
             ' (', $segment['waiting_time'], " с)\n";
    }
}
```

### Дополнительные параметры

Методы `events()`, `history()` и `ways()` принимают последним аргументом массив
дополнительных параметров (см. документацию StarLine):

```php
$events = $api->devices()->events($deviceId, $from, $to, ['limit' => 100]);
```

---

## Произвольные запросы к API

Для эндпоинтов, не покрытых обёртками библиотеки:

```php
// GET с query-параметрами
$data = $api->get('/json/v3/device/' . $deviceId . '/data', ['param' => 'value']);

// POST с JSON-телом
$data = $api->post('/json/v1/device/' . $deviceId . '/set_param', [
    'security' => ['arm' => true],
]);

// Любой метод
$data = $api->request('GET', 'https://developer.starline.ru/json/v3/some/endpoint', [
    'filter' => 'active',
]);
```

`get()` и `post()` автоматически извлекают содержимое ключа `desc` из ответа.
`request()` возвращает то же самое. Для сырого ответа API используйте
низкоуровневый `HttpClientInterface`.

---

## Явная установка user_id

Если автоопределение `user_id` не сработало (редкий случай):

```php
$api = new StarlineApi(/*...*/);
$api->setUserId(123456);

// Теперь все запросы пойдут с этим user_id
$info = $api->user()->info();
```

---

## Кастомное хранилище токенов (Laravel)

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

// Использование
$api = new StarlineApi(
    appId: 123456,
    appSecret: '***',
    tokenStorage: new CacheTokenStorage(),
);
```

---

## Кастомный HTTP-клиент (Guzzle)

```php
use GuzzleHttp\Client;
use Cruide\StarlineApi\Http\HttpClientInterface;
use Cruide\StarlineApi\Http\Response;

final class GuzzleHttpClient implements HttpClientInterface
{
    public function __construct(private Client $client = new Client()) {}

    public function get(string $url, array $query = [], array $headers = []): Response
    {
        $res = $this->client->get($url, ['query' => $query, 'headers' => $headers]);

        return new Response($res->getStatusCode(), (string) $res->getBody(), $res->getHeaders());
    }

    public function postForm(string $url, array $data = [], array $headers = []): Response
    {
        $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        $res = $this->client->post($url, [
            'headers' => $headers,
            'form_params' => $data,
        ]);

        return new Response($res->getStatusCode(), (string) $res->getBody(), $res->getHeaders());
    }

    public function postJson(string $url, array $data = [], array $headers = []): Response
    {
        $res = $this->client->post($url, [
            'headers' => $headers,
            'json' => $data,
        ]);

        return new Response($res->getStatusCode(), (string) $res->getBody(), $res->getHeaders());
    }
}

// Использование
$api = new StarlineApi(
    appId: 123456,
    appSecret: '***',
    httpClient: new GuzzleHttpClient(),
);
```

---

## Обработка ошибок

```php
use Cruide\StarlineApi\Exceptions\StarlineApiException;
use Cruide\StarlineApi\Exceptions\StarlineAuthCaptchaException;
use Cruide\StarlineApi\Exceptions\StarlineAuthException;
use Cruide\StarlineApi\Exceptions\StarlineHttpException;

try {
    $state = $api->devices()->state($deviceId);
} catch (StarlineAuthException $e) {
    // Неверные App ID/Secret или логин/пароль.
    // Автоматический retry при 401 уже выполнен — здесь финальный провал.
    echo 'Ошибка авторизации: ', $e->getMessage(), PHP_EOL;
} catch (StarlineAuthCaptchaException $e) {
    // Требуется капча или SMS-код.
    // Если OCR настроен — исключение означает, что даже авто-ретрай не помог.
    if ($e->isCaptchaRequired()) {
        echo 'Требуется капча: ', $e->getCaptchaImg(), PHP_EOL;
    }
    if ($e->isSmsRequired()) {
        echo 'Требуется SMS на номер: ', $e->getPhone(), PHP_EOL;
    }
} catch (StarlineApiException $e) {
    // Ошибка API: невалидные параметры, недоступный эндпоинт и т.д.
    echo 'Ошибка API: ', $e->getMessage(), PHP_EOL;
    print_r($e->getRaw()); // сырой ответ сервера
} catch (StarlineHttpException $e) {
    // Сетевая ошибка: нет связи, таймаут, проблемы cURL.
    echo 'Сетевая ошибка: ', $e->getMessage(), PHP_EOL;
}
```

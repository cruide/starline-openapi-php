# Starline OpenApi for PHP

> Author: [Alexander Tischenko](http://alex-tisch.ru)

> [Русская версия](README_RU.md)

PHP client library for [StarLine OpenAPI](https://developer.starline.ru/) —
telematics for StarLine security systems: vehicle state, remote commands,
events and tracking.

- PHP **>= 8.0**, only `ext-curl` and `ext-json` — zero external dependencies;
- full SLID authentication with token caching and automatic re-auth on 401;
- captcha and SMS confirmation support during login;
- automatic captcha solving (pure PHP GD or Tesseract OCR);
- StarLineID token authentication (skip SLID chain);
- typed models (`UserInfo`, `Device`, `DeviceState`);
- universal `request()` for any endpoint from the docs;
- swappable HTTP client (interface) — easily plug in Guzzle/PSR-18.

> This library is not affiliated with NPO StarLine. You are responsible for
> API usage (see StarLine terms of service).

## Installation

```bash
composer require cruide/starline-openapi-php
```

Or locally via `repositories` in composer.json:

```json
{
    "repositories": [
        { "type": "path", "url": "../cruide/starline-openapi-php" }
    ]
}
```

## Getting App ID and Secret Key

1. Register at the developer portal: https://developer.starline.ru
2. Create an application — obtain your **App ID** and **Secret Key**.

## Authentication flow (SLID)

| Step | Request | Parameters | Result |
|------|---------|------------|--------|
| 1 | `GET id.starline.ru/apiV3/application/getCode` | `appId`, `secret=md5(appSecret)` | app code |
| 2 | `GET id.starline.ru/apiV3/application/getToken` | `appId`, `secret=md5(appSecret+code)` | app token |
| 3 | `POST id.starline.ru/apiV3/user/login` | Header: `token: <app_token>`, form: `login`, `pass=sha1(password)` | `user_token` |
| 4 | `POST developer.starline.ru/json/v2/auth.slid` | JSON: `{"slid_token":"<user_token>"}` | cookie `slnet` + `user_id` |

All subsequent requests use the `Cookie: slnet=<token>` header.
The entire chain is executed automatically by the library and cached
in `TokenStorageInterface`.

## Quick start

### SLID authentication

```php
use Cruide\StarlineApi\StarlineApi;
use Cruide\StarlineApi\Auth\FileTokenStorage;
use Cruide\StarlineApi\Auth\GdOcr;

$api = new StarlineApi(
    appId: 123456,
    appSecret: 'your-secret',
    login: 'user@example.com',
    password: 'password',
    tokenStorage: new FileTokenStorage('/var/tmp/starline-tokens.json'),
);

// Automatic captcha solving (pure PHP, ext-gd only)
$api->setOcr(new GdOcr());

$api->authenticate();

foreach ($api->user()->devices() as $device) {
    $state = $api->devices()->state($device->id());

    echo $device->alias(), ': ', $state->isArmed() ? 'armed' : 'disarmed', PHP_EOL;
}
```

### StarLineID token

```php
$api = new StarlineApi(appId: 123, appSecret: '***');
$api->authenticateWithSlidToken('hash:user_id');
```

### Manual captcha / SMS

```php
use Cruide\StarlineApi\Exceptions\StarlineAuthCaptchaException;

try {
    $api->authenticate();
} catch (StarlineAuthCaptchaException $e) {
    if ($e->isCaptchaRequired()) {
        // Show to user: $e->getCaptchaImg()
        $api->authenticateWithCaptcha($e->getCaptchaSid(), $userInput);
    } elseif ($e->isSmsRequired()) {
        // SMS sent to: $e->getPhone()
        $api->authenticateWithSms($smsCode);
    }
}
```

### Auto-captcha + Tesseract (optional, requires `tesseract-ocr`)

```php
use Cruide\StarlineApi\Auth\TesseractOcr;

$api->setOcr(new TesseractOcr(lang: 'eng', psm: '8'));
$api->authenticate();  // captcha solved automatically
```

## API methods

| Method | Description |
|--------|-------------|
| `$api->authenticate(bool $force = false)` | Full SLID authentication |
| `$api->authenticateWithSlidToken(string $token)` | Auth via StarLineID token (skip SLID) |
| `$api->authenticateWithCaptcha(string $sid, string $code)` | Retry auth with captcha |
| `$api->authenticateWithSms(string $code)` | Retry auth with SMS code |
| `$api->authenticateWithCaptchaAuto(OcrInterface $ocr)` | Auto-captcha: download, OCR, retry |
| `$api->setOcr(OcrInterface $ocr)` | Enable automatic captcha solving |
| `$api->user()->id()` | Current user ID |
| `$api->user()->info()` | `UserInfo` (profile + devices) |
| `$api->devices()->list()` | List of `Device` objects |
| `$api->devices()->state($deviceId)` | `DeviceState` (`/json/v3/device/{id}/data`) |
| `$api->devices()->details($deviceId)` | Full device info (`/json/v1/device/{id}/details`) |
| `$api->devices()->position($deviceId)` | Last known position (`/json/v1/device/{id}/position`) |
| `$api->devices()->setParam($deviceId, $params)` | Send command (`/json/v1/device/{id}/set_param`) |
| `$api->devices()->arm/disarm/startEngine/stopEngine($deviceId)` | Common commands |
| `$api->devices()->events($deviceId, $periodStart, $periodEnd)` | Events in time range |
| `$api->devices()->eventType($id)` | Single event type description |
| `$api->devices()->eventTypes()` | All event types library |
| `$api->devices()->ways($deviceId, $begin, $end, $extra)` | GPS track (coordinates, mileage, travel time) |
| `$api->get($path, $query)` / `$api->post($path, $json)` | Generic requests |

## Token storage

By default, tokens live only in process memory. For web apps / daemons,
implement `TokenStorageInterface`, e.g. with Laravel cache:

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

## Error handling

| Exception | When |
|-----------|------|
| `StarlineAuthException` | invalid App ID/Secret/login/password, expired tokens (after one auto-retry) |
| `StarlineAuthCaptchaException` | captcha or SMS code required (if OCR is not configured or failed) |
| `StarlineApiException` | API errors (HTTP >= 400 or `state`/`code` != success); `getRaw()` — raw response |
| `StarlineHttpException` | cURL transport errors |

## Notes

- `md5`/`sha1` in the auth chain — StarLine protocol requirement.
- If `user_id` is not detected automatically, set it explicitly:
  `$api->setUserId(123456);`
- For exact request body formats of commands and event/track parameters,
  check the latest Swagger at https://developer.starline.ru —
  use `$api->request()` for non-standard requests.

## Tests

```bash
composer install
composer test
```

## License

MIT

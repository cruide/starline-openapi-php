<?php

/**
 * Пример использования StarLine API.
 *
 * @author Alexander Tischenko (http://alex-tisch.ru)
 */

require __DIR__ . '/../vendor/autoload.php';

use Cruide\StarlineApi\Auth\FileTokenStorage;
use Cruide\StarlineApi\StarlineApi;

// 1. Зарегистрируйте приложение в кабинете разработчика StarLine
//    (https://developer.starline.ru) и получите App ID и Secret Key.
$appId = (int) getenv('STARLINE_APP_ID');
$appSecret = (string) getenv('STARLINE_APP_SECRET');
$login = (string) getenv('STARLINE_LOGIN');
$password = (string) getenv('STARLINE_PASSWORD');

$api = new StarlineApi(
    appId: $appId,
    appSecret: $appSecret,
    login: $login,
    password: $password,
    // Кэш токенов между запусками скрипта:
    tokenStorage: new FileTokenStorage(sys_get_temp_dir() . '/starline-tokens.json'),
);

// 2. Авторизация (slnet будет закэширован; при 401 библиотека переавторизуется сама).
$api->authenticate();

// 3. Пользователь и его устройства.
$user = $api->user()->info();
printf("Пользователь: %s (%s)\n", $user->name() ?? '?', $user->email() ?? '?');

foreach ($user->devices() as $device) {
    printf(
        " - [%d] %s, модель %s, %s\n",
        $device->id(),
        $device->alias() ?? 'без имени',
        $device->type() ?? '?',
        $device->isOnline() ? 'онлайн' : 'офлайн'
    );
}

// 4. Состояние первого устройства.
$device = $user->devices()[0] ?? null;

if ($device !== null && $device->id() !== null) {
    $state = $api->devices()->state($device->id());

    printf("Охрана: %s\n", $state->isArmed() ? 'включена' : 'выключена');
    printf("Двигатель: %s\n", $state->isEngineRunning() ? 'работает' : 'остановлен');
    printf("Температура в салоне: %s °C\n", $state->interiorTemperature() ?? '?');
    printf("Температура двигателя: %s °C\n", $state->engineTemperature() ?? '?');
    printf("Напряжение АКБ: %s В\n", $state->batteryVoltage() ?? '?');
    printf("Координаты: %s, %s\n", $state->latitude() ?? '?', $state->longitude() ?? '?');

    // 5. Команды (раскомментируйте при необходимости):
    // $api->devices()->startEngine($device->id());
    // $api->devices()->stopEngine($device->id());
    // $api->devices()->arm($device->id());
    // $api->devices()->disarm($device->id());

    // 6. Произвольный запрос к любому эндпоинту из документации:
    // $events = $api->devices()->events($device->id(), time() - 86400, time());
}
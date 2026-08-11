<?php namespace Cruide\StarlineApi\Api;

use Cruide\StarlineApi\Models\Device;
use Cruide\StarlineApi\Models\DeviceState;
use Cruide\StarlineApi\StarlineApi;

/**
 * Методы работы с устройствами StarLine.
 *
 * @author Alexander Tischenko (http://alex-tisch.ru)
 */
final class DeviceApi
{
    public function __construct(private StarlineApi $client)
    {
    }

    /**
     * Список устройств пользователя (через user_info).
     *
     * @return Device[]
     */
    public function list(): array
    {
        return $this->client->user()->devices();
    }

    /**
     * Текущее состояние устройства.
     *
     * GET /json/v3/device/{device_id}/data
     */
    public function state(int|string $deviceId): DeviceState
    {
        $data = $this->client->get(sprintf('/json/v3/device/%s/data', $deviceId));

        return new DeviceState($data);
    }

    /**
     * Текущее (последнее зафиксированное) местоположение устройства.
     *
     * GET /json/v1/device/{device_id}/position
     *
     * @deprecated В StarLine API. Рекомендуется использовать state() (/json/v3/device/{id}/data).
     */
    public function position(int|string $deviceId): array
    {
        return $this->client->get(sprintf('/json/v1/device/%s/position', $deviceId));
    }

    /**
     * Установка параметров (команды устройству).
     *
     * POST /json/v1/device/{device_id}/set_param
     *
     * Примеры тел запроса:
     *  ['security' => ['arm' => true]]  — постановка на охрану;
     *  ['security' => ['arm' => false]] — снятие с охраны;
     *  ['engine' => ['start' => true]]  — дистанционный запуск двигателя;
     *  ['engine' => ['stop' => true]]   — остановка двигателя.
     *
     * @param int|string $deviceId Идентификатор устройства.
     * @param array<mixed> $params Параметры.
     * @return array<mixed> Ответ API (desc).
     */
    public function setParam(int|string $deviceId, array $params): array
    {
        return $this->client->post(sprintf('/json/v1/device/%s/set_param', $deviceId), $params);
    }

    /**
     * Постановка на охрану.
     *
     * @return array<mixed>
     */
    public function arm(int|string $deviceId): array
    {
        return $this->setParam($deviceId, ['security' => ['arm' => true]]);
    }

    /**
     * Снятие с охраны.
     *
     * @return array<mixed>
     */
    public function disarm(int|string $deviceId): array
    {
        return $this->setParam($deviceId, ['security' => ['arm' => false]]);
    }

    /**
     * Дистанционный запуск двигателя.
     *
     * @return array<mixed>
     */
    public function startEngine(int|string $deviceId): array
    {
        return $this->setParam($deviceId, ['engine' => ['start' => true]]);
    }

    /**
     * Остановка двигателя.
     *
     * @return array<mixed>
     */
    public function stopEngine(int|string $deviceId): array
    {
        return $this->setParam($deviceId, ['engine' => ['stop' => true]]);
    }

    /**
     * События устройства за период.
     *
     * GET /json/v3/device/{device_id}/events
     *
     * @param int $tsFrom Начало периода, unixtime.
     * @param int $tsTo Конец периода, unixtime.
     * @param array<string, scalar|null> $extra Дополнительные query-параметры.
     * @return array<mixed>
     */
    public function events(int|string $deviceId, int $tsFrom, int $tsTo, array $extra = []): array
    {
        return $this->client->get(
            sprintf('/json/v3/device/%s/events', $deviceId),
            array_merge(['ts_from' => $tsFrom, 'ts_to' => $tsTo], $extra)
        );
    }

    /**
     * Трек устройства (GPS-координаты, пробег, время в движении) за период.
     *
     * POST /json/v1/device/{device_id}/ways
     *
     * @param int $begin Начало периода, unixtime.
     * @param int $end Конец периода, unixtime.
     * @param array<string, scalar|null> $extra Дополнительные параметры (split_way, short_parking, long_parking, dt_max, div_days, time_shift, tz, filtering).
     * @return array<mixed>
     */
    public function ways(int|string $deviceId, int $begin, int $end, array $extra = []): array
    {
        return $this->client->post(
            sprintf('/json/v1/device/%s/ways', $deviceId),
            array_merge(['begin' => $begin, 'end' => $end], $extra)
        );
    }

    /**
     * История перемещений/стоянок (GPS-трек) за период.
     *
     * GET /json/v3/device/{device_id}/history
     *
     * @param int $from Начало периода, unixtime.
     * @param int $to Конец периода, unixtime.
     * @param array<string, scalar|null> $extra Дополнительные query-параметры.
     * @return array<mixed>
     */
    public function history(int|string $deviceId, int $from, int $to, array $extra = []): array
    {
        return $this->client->get(
            sprintf('/json/v3/device/%s/history', $deviceId),
            array_merge(['from' => $from, 'to' => $to], $extra)
        );
    }
}
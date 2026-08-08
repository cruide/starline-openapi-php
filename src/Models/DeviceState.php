<?php namespace StarlineApi\Models;

use StarlineApi\Support\Arr;

/**
 * Текущее состояние устройства (GET /json/v3/device/{id}/data).
 *
 * @author Alexander Tischenko (http://alex-tisch.ru)
 *
 * Структура ответа может отличаться в разных прошивках, поэтому модель:
 * - хранит сырой массив целиком (raw());
 * - предоставляет защищённые типизированные геттеры для типовых полей,
 *   которые возвращают null, если поле отсутствует.
 */
final class DeviceState
{
    /**
     * @param array<mixed> $raw Содержимое desc ответа.
     */
    public function __construct(private array $raw)
    {
    }

    /**
     * @return array<mixed> Сырые данные состояния.
     */
    public function raw(): array
    {
        return $this->raw;
    }

    public function deviceId(): ?int
    {
        $id = Arr::get($this->raw, 'device_id');

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * Включена ли охрана.
     */
    public function isArmed(): ?bool
    {
        $value = Arr::get($this->raw, 'security.arm');

        return is_bool($value) ? $value : null;
    }

    /**
     * Работает ли двигатель.
     */
    public function isEngineRunning(): ?bool
    {
        foreach (['engine.running', 'engine'] as $path) {
            $value = Arr::get($this->raw, $path);

            if (is_bool($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Температура в салоне, °C.
     */
    public function interiorTemperature(): ?float
    {
        return $this->float('interior_temp', 'temperature.interior');
    }

    /**
     * Температура двигателя, °C.
     */
    public function engineTemperature(): ?float
    {
        return $this->float('engine_temp', 'temperature.engine');
    }

    /**
     * Напряжение АКБ, В.
     */
    public function batteryVoltage(): ?float
    {
        return $this->float('battery.voltage', 'voltage');
    }

    /**
     * Баланс SIM-карты GSM.
     */
    public function gsmBalance(): ?float
    {
        return $this->float('balance.value', 'balance');
    }

    public function latitude(): ?float
    {
        return $this->float('gps.lat', 'position.lat', 'latitude');
    }

    public function longitude(): ?float
    {
        return $this->float('gps.lon', 'position.lon', 'longitude');
    }

    /**
     * Пробег, км (если поддерживается устройством).
     */
    public function mileage(): ?int
    {
        $value = Arr::get($this->raw, 'mileage');

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Время последнего обновления состояния (unixtime).
     */
    public function updatedAt(): ?int
    {
        foreach (['timestamp', 'updated_at', 'ts'] as $path) {
            $value = Arr::get($this->raw, $path);

            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    /**
     * @param string ...$paths Кандидаты на путь в массиве.
     */
    private function float(string ...$paths): ?float
    {
        foreach ($paths as $path) {
            $value = Arr::get($this->raw, $path);

            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }
}
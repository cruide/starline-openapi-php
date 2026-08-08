<?php namespace StarlineApi\Models;

use StarlineApi\Support\Arr;

/**
 * Устройство StarLine (сигнализация) из user_info.
 *
 * @author Alexander Tischenko (http://alex-tisch.ru)
 */
final class Device
{
    /**
     * @param array<mixed> $raw Сырые данные устройства.
     */
    public function __construct(private array $raw)
    {
    }

    /**
     * Идентификатор устройства (device_id).
     */
    public function id(): ?int
    {
        $id = Arr::get($this->raw, 'device_id', Arr::get($this->raw, 'id'));

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * Модель, например "S96 v2".
     */
    public function type(): ?string
    {
        $value = Arr::get($this->raw, 'device_type', Arr::get($this->raw, 'type'));

        return is_string($value) ? $value : null;
    }

    /**
     * Пользовательское имя устройства.
     */
    public function alias(): ?string
    {
        $value = Arr::get($this->raw, 'alias', Arr::get($this->raw, 'name'));

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * IMEI GSM-модуля.
     */
    public function imei(): ?string
    {
        $value = Arr::get($this->raw, 'imei');

        return is_string($value) ? $value : null;
    }

    /**
     * Онлайн ли устройство.
     */
    public function isOnline(): ?bool
    {
        $value = Arr::get($this->raw, 'online');

        return is_bool($value) ? $value : null;
    }

    /**
     * @return array<mixed> Сырые данные.
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
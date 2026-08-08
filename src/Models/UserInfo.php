<?php namespace Cruide\StarlineApi\Models;

use Cruide\StarlineApi\Support\Arr;

/**
 * Информация о пользователе StarLine и его устройствах.
 *
 * @author Alexander Tischenko (http://alex-tisch.ru)
 */
final class UserInfo
{
    /**
     * @param array<mixed> $raw Содержимое desc из /json/v1/user/{id}/user_info.
     */
    public function __construct(private array $raw)
    {
    }

    public function id(): ?int
    {
        $id = Arr::get($this->raw, 'id');

        return is_numeric($id) ? (int) $id : null;
    }

    public function name(): ?string
    {
        $value = Arr::get($this->raw, 'name');

        return is_string($value) ? $value : null;
    }

    public function email(): ?string
    {
        $value = Arr::get($this->raw, 'email');

        return is_string($value) ? $value : null;
    }

    /**
     * Устройства пользователя.
     *
     * @return Device[]
     */
    public function devices(): array
    {
        $devices = Arr::get($this->raw, 'devices', []);

        if (!is_array($devices)) {
            return [];
        }

        $result = [];

        foreach ($devices as $device) {
            if (is_array($device)) {
                $result[] = new Device($device);
            }
        }

        return $result;
    }

    /**
     * @return array<mixed> Сырые данные.
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
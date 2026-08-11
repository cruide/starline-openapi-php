<?php namespace Cruide\StarlineApi\Api;

use Cruide\StarlineApi\Models\Device;
use Cruide\StarlineApi\Models\UserInfo;
use Cruide\StarlineApi\StarlineApi;

/**
 * Методы, связанные с пользователем.
 *
 * @author Alexander Tischenko (http://alex-tisch.ru)
 */
final class UserApi
{
    public function __construct(private StarlineApi $client)
    {
    }

    /**
     * Идентификатор текущего пользователя.
     */
    public function id(): int
    {
        return $this->client->authenticator()->getUserId();
    }

    /**
     * Информация о пользователе и его устройствах.
     *
     * GET /json/v2/user/{user_id}/user_info
     */
    public function info(): UserInfo
    {
        $data = $this->client->get('/json/v2/user/' . $this->id() . '/user_info');

        return new UserInfo($data);
    }

    /**
     * Устройства текущего пользователя.
     *
     * @return Device[]
     */
    public function devices(): array
    {
        return $this->info()->devices();
    }
}
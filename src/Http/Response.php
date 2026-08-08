<?php namespace StarlineApi\Http;
/**
 * Ответ HTTP-клиента.
 *
 * @author Alexander Tischenko (http://alex-tisch.ru)
 */
final class Response
{
    /**
     * @param int $statusCode HTTP-статус.
     * @param string $body Тело ответа.
     * @param array<string, string[]> $headers Заголовки (имя в нижнем регистре => список значений).
     */
    public function __construct(
        public int $statusCode,
        public string $body,
        public array $headers = []
    ) {
    }

    /**
     * Получить значение cookie по имени из заголовков Set-Cookie.
     *
     * Критично для SLID-авторизации: именно так извлекается cookie `slnet`
     * из ответа POST /json/v2/auth.slid.
     *
     * @param string $name Имя cookie.
     * @return string|null Значение или null, если cookie нет.
     */
    public function cookie(string $name): ?string
    {
        foreach ($this->headers['set-cookie'] ?? [] as $cookieLine) {
            $pair = trim(explode(';', $cookieLine, 2)[0]);
            $position = strpos($pair, '=');

            if ($position === false) {
                continue;
            }

            if (rtrim(substr($pair, 0, $position)) === $name) {
                return substr($pair, $position + 1);
            }
        }

        return null;
    }

    /**
     * Разобрать тело ответа как JSON.
     *
     * @return array<mixed>|null Массив или null, если тело не является корректным JSON.
     */
    public function json(): ?array
    {
        $data = json_decode($this->body, true);

        return is_array($data) ? $data : null;
    }
}
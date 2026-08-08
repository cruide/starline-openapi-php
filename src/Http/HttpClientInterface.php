<?php namespace StarlineApi\Http;
/**
 * Контракт HTTP-клиента.
 *
 * @author Alexander Tischenko (http://alex-tisch.ru)
 *
 * Реализация по умолчанию — {@see CurlHttpClient}. При необходимости
 * легко подменяется на Guzzle/PSR-18 адаптер (например, в Laravel-приложении).
 */
interface HttpClientInterface
{
    /**
     * GET-запрос.
     *
     * @param string $url URL.
     * @param array<string, scalar|null> $query Query-параметры.
     * @param array<string, string> $headers Заголовки.
     */
    public function get(string $url, array $query = [], array $headers = []): Response;

    /**
     * POST-запрос с form-телом (application/x-www-form-urlencoded).
     *
     * @param string $url URL.
     * @param array<string, scalar|null> $data Поля формы.
     * @param array<string, string> $headers Заголовки.
     */
    public function postForm(string $url, array $data = [], array $headers = []): Response;

    /**
     * POST-запрос с JSON-телом.
     *
     * @param string $url URL.
     * @param array<mixed> $data Данные для JSON-тела.
     * @param array<string, string> $headers Заголовки.
     */
    public function postJson(string $url, array $data = [], array $headers = []): Response;
}
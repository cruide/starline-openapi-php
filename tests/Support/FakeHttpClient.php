<?php namespace StarlineApi\Tests\Support;

use StarlineApi\Http\HttpClientInterface;
use StarlineApi\Http\Response;

/**
 * Фейковый HTTP-клиент для тестов: очередь заготовленных ответов
 * и журнал запросов.
 *
 * @author Alexander Tischenko (http://alex-tisch.ru)
 */
final class FakeHttpClient implements HttpClientInterface
{
    /** @var Response[] */
    private array $queue = [];

    /** @var array<int, array{method: string, url: string, data: array<mixed>, headers: array<string, string>}> */
    public array $requests = [];

    public function push(Response $response): void
    {
        $this->queue[] = $response;
    }

    public function get(string $url, array $query = [], array $headers = []): Response
    {
        $this->requests[] = ['method' => 'GET', 'url' => $url, 'data' => $query, 'headers' => $headers];

        return $this->next();
    }

    public function postForm(string $url, array $data = [], array $headers = []): Response
    {
        $this->requests[] = ['method' => 'POST_FORM', 'url' => $url, 'data' => $data, 'headers' => $headers];

        return $this->next();
    }

    public function postJson(string $url, array $data = [], array $headers = []): Response
    {
        $this->requests[] = ['method' => 'POST_JSON', 'url' => $url, 'data' => $data, 'headers' => $headers];

        return $this->next();
    }

    private function next(): Response
    {
        if ($this->queue === []) {
            throw new \LogicException('Нет заготовленных ответов в FakeHttpClient.');
        }

        return array_shift($this->queue);
    }
}
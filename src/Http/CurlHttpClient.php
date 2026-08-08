<?php namespace StarlineApi\Http;

use StarlineApi\Exceptions\StarlineHttpException;

/**
 * HTTP-клиент на cURL (без внешних зависимостей).
 *
 * @author Alexander Tischenko (http://alex-tisch.ru)
 */
final class CurlHttpClient implements HttpClientInterface
{
    /**
     * @param float $timeout Общий таймаут запроса, секунд.
     * @param string $userAgent User-Agent.
     */
    public function __construct(
        private float $timeout = 30.0,
        private string $userAgent = 'StarlineApi-PHP/1.0'
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function get(string $url, array $query = [], array $headers = []): Response
    {
        if ($query !== []) {
            $separator = str_contains($url, '?') ? '&' : '?';
            $url .= $separator . http_build_query($query, '', '&');
        }

        return $this->execute('GET', $url, $headers);
    }

    /**
     * {@inheritDoc}
     */
    public function postForm(string $url, array $data = [], array $headers = []): Response
    {
        $headers['Content-Type'] = 'application/x-www-form-urlencoded';

        return $this->execute('POST', $url, $headers, http_build_query($data, '', '&'));
    }

    /**
     * {@inheritDoc}
     */
    public function postJson(string $url, array $data = [], array $headers = []): Response
    {
        $headers['Content-Type'] = 'application/json';

        return $this->execute(
            'POST',
            $url,
            $headers,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );
    }

    /**
     * Выполнить HTTP-запрос.
     *
     * @param string $method HTTP-метод.
     * @param string $url URL.
     * @param array<string, string> $headers Заголовки.
     * @param string|null $body Тело запроса.
     */
    private function execute(string $method, string $url, array $headers, ?string $body = null): Response
    {
        $ch = curl_init();

        if ($ch === false) {
            throw new StarlineHttpException('Не удалось инициализировать cURL.');
        }

        /** @var array<string, string[]> $responseHeaders */
        $responseHeaders = [];

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => (int) ceil($this->timeout),
            CURLOPT_CONNECTTIMEOUT => min((int) ceil($this->timeout), 15),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_ACCEPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => function ($curl, string $headerLine) use (&$responseHeaders): int {
                $length = strlen($headerLine);
                $parts = explode(':', $headerLine, 2);

                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))][] = trim($parts[1]);
                }

                return $length;
            },
        ];

        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $body ?? '';
        } elseif ($method !== 'GET') {
            $options[CURLOPT_CUSTOMREQUEST] = $method;

            if ($body !== null) {
                $options[CURLOPT_POSTFIELDS] = $body;
            }
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        if ($headerLines !== []) {
            $options[CURLOPT_HTTPHEADER] = $headerLines;
        }

        curl_setopt_array($ch, $options);

        $responseBody = curl_exec($ch);

        if ($responseBody === false) {
            $error = curl_error($ch);
            $errno = curl_errno($ch);
            curl_close($ch);

            throw new StarlineHttpException(sprintf('cURL error %d: %s (URL: %s)', $errno, $error, $url));
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return new Response($statusCode, (string) $responseBody, $responseHeaders);
    }
}
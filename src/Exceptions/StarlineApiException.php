<?php namespace Cruide\StarlineApi\Exceptions;
/**
 * Ошибка уровня API: HTTP >= 400 либо конверт ответа с ошибочным state/code.
 *
 * @author Alexander Tischenko (http://alex-tisch.ru)
 */
class StarlineApiException extends StarlineException
{
    /** @var array<mixed> Сырой ответ API (если удалось разобрать). */
    private array $raw;

    /**
     * @param string $message Сообщение об ошибке.
     * @param int $apiCode Код ошибки API/HTTP.
     * @param array<mixed> $raw Сырой ответ API.
     * @param \Throwable|null $previous Предыдущее исключение.
     */
    public function __construct(string $message, int $apiCode = 0, array $raw = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, $apiCode, $previous);

        $this->raw = $raw;
    }

    /**
     * @return array<mixed>
     */
    public function getRaw(): array
    {
        return $this->raw;
    }
}
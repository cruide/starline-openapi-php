<?php namespace Cruide\StarlineApi\Auth;
/**
 * Хранилище токенов в памяти процесса (по умолчанию).
 *
 * @author Alexander Tischenko (http://alex-tisch.ru)
 *
 * Подходит для one-shot скриптов; для демонов/веба используйте
 * {@see FileTokenStorage} или собственную реализацию (например, кэш Laravel).
 */
final class InMemoryTokenStorage implements TokenStorageInterface
{
    /** @var array<string, array{0: string, 1: int|null}> */
    private array $items = [];

    /**
     * {@inheritDoc}
     */
    public function get(string $key): ?string
    {
        if (!isset($this->items[$key])) {
            return null;
        }

        [$value, $expiresAt] = $this->items[$key];

        if ($expiresAt !== null && $expiresAt < time()) {
            unset($this->items[$key]);

            return null;
        }

        return $value;
    }

    /**
     * {@inheritDoc}
     */
    public function set(string $key, string $value, ?int $ttl = null): void
    {
        $this->items[$key] = [$value, $ttl === null ? null : time() + $ttl];
    }

    /**
     * {@inheritDoc}
     */
    public function delete(string $key): void
    {
        unset($this->items[$key]);
    }
}
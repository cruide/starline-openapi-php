<?php namespace Cruide\StarlineApi\Auth;
/**
 * Контракт хранилища токенов.
 *
 * @author Alexander Tischenko (http://alex-tisch.ru)
 *
 * Токены StarLine живут ограниченное время; чтобы не выполнять
 * полную SLID-цепочку на каждый запрос, их нужно кэшировать.
 * В Laravel легко реализуемо через Cache::get/put.
 */
interface TokenStorageInterface
{
    /**
     * @param string $key Ключ.
     * @return string|null Значение или null (нет/истекло).
     */
    public function get(string $key): ?string;

    /**
     * @param string $key Ключ.
     * @param string $value Значение.
     * @param int|null $ttl Время жизни в секундах (null — без ограничения).
     */
    public function set(string $key, string $value, ?int $ttl = null): void;

    /**
     * @param string $key Ключ.
     */
    public function delete(string $key): void;
}
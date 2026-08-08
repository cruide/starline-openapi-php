<?php namespace Cruide\StarlineApi\Support;
/**
 * Вспомогательные методы для работы с массивами (аналог Arr::get из Laravel).
 *
 * @author Alexander Tischenko (http://alex-tisch.ru)
 *
 * @internal
 */
final class Arr
{
    /**
     * Получить значение из вложенного массива по dot-пути.
     *
     * @param array<mixed> $data Исходный массив.
     * @param string $path Путь вида "security.arm".
     * @param mixed $default Значение по умолчанию.
     * @return mixed
     */
    public static function get(array $data, string $path, mixed $default = null): mixed
    {
        foreach (explode('.', $path) as $segment) {
            if (!is_array($data) || !array_key_exists($segment, $data)) {
                return $default;
            }

            $data = $data[$segment];
        }

        return $data;
    }
}
<?php namespace Cruide\StarlineApi\Auth;

/**
 * Контракт для распознавания изображений капчи.
 *
 * @author Alexander Tischenko (http://alex-tisch.ru)
 */
interface OcrInterface
{
    /**
     * Распознать текст на изображении капчи.
     *
     * @param string $imageData Бинарные данные изображения (PNG/JPEG/GIF).
     * @return string|null Распознанный текст или null при неудаче.
     */
    public function decode(string $imageData): ?string;
}

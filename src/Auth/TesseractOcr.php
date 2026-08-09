<?php namespace Cruide\StarlineApi\Auth;

use Cruide\StarlineApi\Exceptions\StarlineException;

/**
 * Распознаватель капчи через Tesseract OCR (командная строка).
 *
 * Требует установленного пакета tesseract-ocr в системе.
 * Поддерживает указание языка (например, 'eng' для цифр/букв).
 *
 * Это более надёжная альтернатива {@see GdOcr} при наличии tesseract.
 *
 * @author Alexander Tischenko (http://alex-tisch.ru)
 */
final class TesseractOcr implements OcrInterface
{
    /**
     * @param string $lang Язык для tesseract (по умолчанию 'eng').
     * @param string $psm Page segmentation mode (по умолчанию '8' — single word).
     * @param string $extraFlags Дополнительные флаги командной строки (например, '-c tessedit_char_whitelist=0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ').
     */
    public function __construct(
        private string $lang = 'eng',
        private string $psm = '8',
        private string $extraFlags = ''
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function decode(string $imageData): ?string
    {
        $tmpFile = $this->writeTempFile($imageData);

        if ($tmpFile === null) {
            return null;
        }

        try {
            return $this->runTesseract($tmpFile);
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * Записать бинарные данные во временный файл.
     */
    private function writeTempFile(string $imageData): ?string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'starline_captcha_');

        if ($tmpFile === false) {
            return null;
        }

        $tmpFile .= '.png';

        if (file_put_contents($tmpFile, $imageData) === false) {
            @unlink($tmpFile);

            return null;
        }

        return $tmpFile;
    }

    /**
     * Запустить tesseract и вернуть распознанный текст.
     */
    private function runTesseract(string $imagePath): ?string
    {
        $outputBase = $imagePath . '_out';

        $cmd = sprintf(
            'tesseract %s %s -l %s --psm %s %s 2>&1',
            escapeshellarg($imagePath),
            escapeshellarg($outputBase),
            escapeshellarg($this->lang),
            escapeshellarg($this->psm),
            $this->extraFlags
        );

        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            @unlink($outputBase . '.txt');

            return null;
        }

        $txtFile = $outputBase . '.txt';

        if (!file_exists($txtFile)) {
            return null;
        }

        $text = file_get_contents($txtFile);
        @unlink($txtFile);

        if ($text === false) {
            return null;
        }

        // Убираем пробелы и переводы строк — капча это одно слово
        $text = preg_replace('/\s+/', '', $text);

        return $text !== '' ? $text : null;
    }
}

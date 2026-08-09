<?php namespace Cruide\StarlineApi\Tests\Auth;

use PHPUnit\Framework\TestCase;
use Cruide\StarlineApi\Auth\GdOcr;
use Cruide\StarlineApi\Auth\OcrInterface;

/**
 * @author Alexander Tischenko (http://alex-tisch.ru)
 */
final class GdOcrTest extends TestCase
{
    public function testImplementsInterface(): void
    {
        $ocr = new GdOcr();
        self::assertInstanceOf(OcrInterface::class, $ocr);
    }

    public function testDecodeSimpleDigits(): void
    {
        $ocr = new GdOcr();
        $imgData = $this->generateCaptionImage('42');

        $result = $ocr->decode($imgData);
        self::assertNotNull($result);
        // Хотя бы непустой ответ
        self::assertNotEmpty($result);
    }

    public function testDecodeReturnsNullForInvalidData(): void
    {
        $ocr = new GdOcr();
        $result = $ocr->decode('not-an-image');

        self::assertNull($result);
    }

    public function testDecodeReturnsNullForEmptyImage(): void
    {
        $ocr = new GdOcr();
        $result = $ocr->decode('');

        self::assertNull($result);
    }

    public function testCustomThreshold(): void
    {
        $ocr = new GdOcr(threshold: 128);
        $imgData = $this->generateCaptionImage('73');

        $result = $ocr->decode($imgData);
        self::assertNotNull($result);
        self::assertNotEmpty($result);
    }

    public function testMinSymbolWidth(): void
    {
        $ocr = new GdOcr(minSymbolWidth: 6);
        $imgData = $this->generateCaptionImage('99');

        $result = $ocr->decode($imgData);
        self::assertNotNull($result);
        self::assertNotEmpty($result);
    }

    /**
     * Генерирует простое PNG-изображение с текстом (чистый белый фон, чёрный текст).
     */
    private function generateCaptionImage(string $text): string
    {
        $w = 80;
        $h = 30;
        $img = imagecreate($w, $h);

        if ($img === false) {
            self::fail('GD не может создать изображение.');
        }

        $white = (int) imagecolorallocate($img, 255, 255, 255);
        $black = (int) imagecolorallocate($img, 0, 0, 0);

        imagefill($img, 0, 0, $white);

        // Рисуем текст крупным встроенным шрифтом
        imagestring($img, 5, 10, 5, $text, $black);

        ob_start();
        imagepng($img);
        $data = (string) ob_get_clean();
        imagedestroy($img);

        return $data;
    }
}

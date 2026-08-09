<?php namespace Cruide\StarlineApi\Tests\Auth;

use PHPUnit\Framework\TestCase;
use Cruide\StarlineApi\Auth\OcrInterface;
use Cruide\StarlineApi\Auth\TesseractOcr;

/**
 * @author Alexander Tischenko (http://alex-tisch.ru)
 */
final class TesseractOcrTest extends TestCase
{
    public function testImplementsInterface(): void
    {
        $ocr = new TesseractOcr();
        self::assertInstanceOf(OcrInterface::class, $ocr);
    }

    public function testDecodeReturnsNullForInvalidData(): void
    {
        $ocr = new TesseractOcr();
        $result = $ocr->decode('');

        self::assertNull($result);
    }

    public function testDecodeReturnsNullForGarbageData(): void
    {
        $ocr = new TesseractOcr();
        $result = $ocr->decode('not-a-valid-image');

        self::assertNull($result);
    }

    public function testCustomLangAndPsm(): void
    {
        $ocr = new TesseractOcr(lang: 'eng', psm: '7');
        self::assertInstanceOf(TesseractOcr::class, $ocr);
    }

    public function testWhitelistChars(): void
    {
        $ocr = new TesseractOcr(
            extraFlags: '-c tessedit_char_whitelist=0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ'
        );
        self::assertInstanceOf(TesseractOcr::class, $ocr);
    }
}

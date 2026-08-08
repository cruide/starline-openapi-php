<?php namespace StarlineApi\Tests\Http;

use PHPUnit\Framework\TestCase;
use StarlineApi\Http\Response;

final class ResponseTest extends TestCase
{
    public function testCookieExtraction(): void
    {
        $response = new Response(200, '', [
            'set-cookie' => [
                'a=1; Path=/',
                'slnet=XYZ789; Path=/; HttpOnly',
            ],
        ]);

        self::assertSame('XYZ789', $response->cookie('slnet'));
        self::assertSame('1', $response->cookie('a'));
        self::assertNull($response->cookie('missing'));
    }

    public function testJsonDecode(): void
    {
        self::assertSame(['state' => 1], (new Response(200, '{"state":1}'))->json());
        self::assertNull((new Response(200, 'not json'))->json());
    }
}
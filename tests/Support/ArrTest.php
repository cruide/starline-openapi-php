<?php namespace Cruide\StarlineApi\Tests\Support;

use PHPUnit\Framework\TestCase;
use Cruide\StarlineApi\Support\Arr;

/**
 * @author Alexander Tischenko (http://alex-tisch.ru)
 */
final class ArrTest extends TestCase
{
    public function testSimpleKey(): void
    {
        self::assertSame('bar', Arr::get(['foo' => 'bar'], 'foo'));
    }

    public function testNestedKey(): void
    {
        $data = ['a' => ['b' => ['c' => 42]]];
        self::assertSame(42, Arr::get($data, 'a.b.c'));
    }

    public function testDefaultWhenKeyMissing(): void
    {
        self::assertNull(Arr::get([], 'missing'));
        self::assertSame('default', Arr::get([], 'missing', 'default'));
    }

    public function testIntermediateNotArray(): void
    {
        self::assertNull(Arr::get(['x' => 'string'], 'x.y'));
    }

    public function testNullValueIsReturned(): void
    {
        self::assertNull(Arr::get(['x' => null], 'x'));
    }

    public function testEmptyStringPath(): void
    {
        // explode('.', '') returns [''], and array_key_exists('', $data) is false
        // for ['foo' => 'bar'], so the default (null) is returned.
        self::assertNull(Arr::get(['foo' => 'bar'], ''));
    }
}

<?php namespace Cruide\StarlineApi\Tests\Auth;

use PHPUnit\Framework\TestCase;
use Cruide\StarlineApi\Auth\InMemoryTokenStorage;

/**
 * @author Alexander Tischenko (http://alex-tisch.ru)
 */
final class InMemoryTokenStorageTest extends TestCase
{
    public function testSetAndGet(): void
    {
        $storage = new InMemoryTokenStorage();
        $storage->set('key1', 'val1');

        self::assertSame('val1', $storage->get('key1'));
    }

    public function testGetMissingKey(): void
    {
        $storage = new InMemoryTokenStorage();

        self::assertNull($storage->get('nope'));
    }

    public function testDelete(): void
    {
        $storage = new InMemoryTokenStorage();
        $storage->set('key1', 'val1');
        $storage->delete('key1');

        self::assertNull($storage->get('key1'));
    }

    public function testDeleteNonExistent(): void
    {
        $storage = new InMemoryTokenStorage();
        $storage->delete('nope');

        self::assertNull($storage->get('nope'));
    }

    public function testExpiredTokenReturnsNull(): void
    {
        $storage = new InMemoryTokenStorage();
        $storage->set('key1', 'val1', -1); // TTL = -1, already expired

        self::assertNull($storage->get('key1'));
    }

    public function testNonExpiredToken(): void
    {
        $storage = new InMemoryTokenStorage();
        $storage->set('key1', 'val1', 3600);

        self::assertSame('val1', $storage->get('key1'));
    }

    public function testOverwrite(): void
    {
        $storage = new InMemoryTokenStorage();
        $storage->set('key1', 'val1');
        $storage->set('key1', 'val2');

        self::assertSame('val2', $storage->get('key1'));
    }
}

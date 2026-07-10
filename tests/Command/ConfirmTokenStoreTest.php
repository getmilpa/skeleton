<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ConfirmTokenStore;
use PHPUnit\Framework\TestCase;

final class ConfirmTokenStoreTest extends TestCase
{
    public function testAFreshTokenIsConsumedExactlyOnce(): void
    {
        $store = new ConfirmTokenStore();
        $token = $store->issue('create_post');

        self::assertTrue($store->consume($token, 'create_post'));
        self::assertFalse($store->consume($token, 'create_post'), 'a token must not be reusable');
    }

    public function testATokenIsBoundToItsOperation(): void
    {
        $store = new ConfirmTokenStore();
        $token = $store->issue('create_post');

        self::assertFalse($store->consume($token, 'delete_post'));
    }

    public function testAnUnknownTokenIsRejected(): void
    {
        $store = new ConfirmTokenStore();

        self::assertFalse($store->consume('nope', 'create_post'));
    }

    public function testAnExpiredTokenIsRejected(): void
    {
        $store = new ConfirmTokenStore(-1); // already past
        $token = $store->issue('create_post');

        self::assertFalse($store->consume($token, 'create_post'));
    }
}

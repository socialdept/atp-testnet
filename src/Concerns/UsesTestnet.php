<?php

declare(strict_types=1);

namespace SocialDept\AtpTestnet\Concerns;

use SocialDept\AtpTestnet\Testnet;
use SocialDept\AtpTestnet\TestnetConfig;

/**
 * Trait for PHPUnit/Pest tests that need a running AT Protocol testnet.
 *
 * Boots the testnet once per test class and tears it down after.
 */
trait UsesTestnet
{
    protected static ?Testnet $testnetInstance = null;

    protected Testnet $testnet;

    /**
     * @beforeClass
     */
    public static function bootTestnet(): void
    {
        if (self::$testnetInstance === null) {
            self::$testnetInstance = Testnet::start(new TestnetConfig);
        }
    }

    /**
     * @afterClass
     */
    public static function tearDownTestnet(): void
    {
        self::$testnetInstance?->stop();
        self::$testnetInstance = null;
    }

    /**
     * @before
     */
    public function setUpTestnetProperty(): void
    {
        $this->testnet = self::$testnetInstance;
    }
}

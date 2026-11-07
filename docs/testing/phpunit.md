# PHPUnit and Pest

Part of the [atp-testnet docs](../introduction.md#documentation). Booting the testnet once per test class with the `UsesTestnet` trait.

The `UsesTestnet` trait boots the testnet before the first test in a class, gives every test a `$this->testnet`, and stops the testnet after the last test in the class:

```php
use PHPUnit\Framework\TestCase;
use SocialDept\AtpTestnet\Concerns\UsesTestnet;

class MyTest extends TestCase
{
    use UsesTestnet;

    public function test_account_creation(): void
    {
        $account = $this->testnet->createAccount('bob');
        $this->assertStringStartsWith('did:plc:', $account->did);
    }
}
```

With Pest, apply the trait with `uses()`:

```php
use SocialDept\AtpTestnet\Concerns\UsesTestnet;

uses(UsesTestnet::class);

test('can create account', function () {
    $account = $this->testnet->createAccount('bob');
    expect($account->did)->toStartWith('did:plc:');
});
```

The trait starts the testnet with the default [config](../getting-started/installation.md#configuration). To use other settings, manage the testnet yourself in `setUpBeforeClass()` and `tearDownAfterClass()`:

```php
use SocialDept\AtpTestnet\Testnet;
use SocialDept\AtpTestnet\TestnetConfig;

private static ?Testnet $testnet = null;

public static function setUpBeforeClass(): void
{
    self::$testnet = Testnet::start(new TestnetConfig(pdsPort: 8102));
}

public static function tearDownAfterClass(): void
{
    self::$testnet?->stop();
    self::$testnet = null;
}
```

Tests in the same class share one testnet, and accounts and records pile up across them. See [Test isolation](isolation.md) to start each test clean.

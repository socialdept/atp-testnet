[![Testnet Header](./header.png)](https://github.com/socialdept/atp-testnet)

<h3 align="center">
    Stand up a local AT Protocol testnet for integration testing in PHP.
</h3>

<p align="center">
    <br>
    <a href="https://packagist.org/packages/socialdept/atp-testnet" title="Latest Version on Packagist"><img src="https://img.shields.io/packagist/v/socialdept/atp-testnet.svg?style=flat-square"></a>
    <a href="https://packagist.org/packages/socialdept/atp-testnet" title="Total Downloads"><img src="https://img.shields.io/packagist/dt/socialdept/atp-testnet.svg?style=flat-square"></a>
    <a href="LICENSE" title="Software License"><img src="https://img.shields.io/github/license/socialdept/atp-testnet?style=flat-square"></a>
</p>

---

This package runs a complete AT Protocol network on your machine with Docker Compose, so integration tests can talk to a real PLC directory, a real PDS and a real relay with a firehose instead of mocks. It creates accounts, signs PLC operations, resets state between tests and can spawn extra PDS containers that a test owns outright. To learn all about it, head over to [the documentation](https://socialde.pt/docs/atp-testnet).

Here are a few short examples of what you can do.

Boot the network and create an account:

```php
use SocialDept\AtpTestnet\Testnet;

$testnet = Testnet::start();

$alice = $testnet->createAccount('alice');
echo $alice->did;    // did:plc:...
echo $alice->handle; // alice.test
```

Write a record and read the DID document back from the PLC directory:

```php
$testnet->pds()->createRecord(
    'app.bsky.feed.post',
    ['$type' => 'app.bsky.feed.post', 'text' => 'Hello from testnet!', 'createdAt' => gmdate('c')],
    $alice->accessJwt,
);

$doc = $testnet->plc()->getDocument($alice->did);
```

Have the relay crawl the PDS and listen to the firehose:

```php
$testnet->requestRelayCrawl();
$frames = $testnet->relay()->consumeFirehose(timeoutMs: 3000);
```

Use it from a test, with a clean slate before each one:

```php
use SocialDept\AtpTestnet\Concerns\UsesTestnet;

uses(UsesTestnet::class);

beforeEach(function () {
    $this->testnet->resetAll();
});
```

## Documentation

You'll find the documentation on [https://socialde.pt/docs/atp-testnet](https://socialde.pt/docs/atp-testnet).

Find yourself stuck using the package? Found a bug? Do you have general questions or suggestions for improving it? Feel free to [create an issue on GitHub](https://github.com/socialdept/atp-testnet/issues).

## Testing

```bash
vendor/bin/phpunit
```

The suite boots a real testnet, so Docker has to be running. CI runs `vendor/bin/phpunit --exclude-group integration`, which skips the tests that spawn extra PDS containers.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## License

The MIT License (MIT). Please see [LICENSE](LICENSE) for more information.

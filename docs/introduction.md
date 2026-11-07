# Introduction

**ATP Testnet** spins up a complete local AT Protocol network with Docker Compose for integration testing. There are no mocks and no fakes:

- **PLC directory:** a real `did:plc` directory, patched so its rate limit can be turned off.
- **PDS:** the official Bluesky PDS image, for accounts, repos, records, blobs and XRPC.
- **Relay:** a real relay that crawls the PDS and serves a firehose.
- **Accounts:** one call creates an account with a session, under the `.test` handle domain.
- **PLC operations:** change a DID's service endpoint, handle or rotation keys, signed with the testnet's rotation key.
- **Test isolation:** reset the PDS, the PLC directory and the relay between tests without restarting a container.
- **Extra PDSes:** spawn disposable PDS containers a test owns outright, with their DIDs in the shared PLC directory.

## A first look

Boot the network and create an account:

```php
use SocialDept\AtpTestnet\Testnet;

$testnet = Testnet::start();

$alice = $testnet->createAccount('alice');
echo $alice->did;    // did:plc:...
echo $alice->handle; // alice.test
```

Write a record, then check the PLC directory and the firehose:

```php
$testnet->pds()->createRecord(
    'app.bsky.feed.post',
    ['$type' => 'app.bsky.feed.post', 'text' => 'Hello from testnet!', 'createdAt' => gmdate('c')],
    $alice->accessJwt,
);

$doc = $testnet->plc()->getDocument($alice->did);

$testnet->requestRelayCrawl();
$frames = $testnet->relay()->consumeFirehose(timeoutMs: 3000);

$testnet->stop();
```

## Documentation

Start with [Installation](getting-started/installation.md), then [Writing tests](testing/README.md) for using the testnet from PHPUnit or Pest. The docs are grouped by area, and each group has an index of its own:

- **[Getting started](getting-started/README.md)**: [Installation](getting-started/installation.md), [Docker images](getting-started/images.md), [Services](getting-started/services.md)
- **[Writing tests](testing/README.md)**: [PHPUnit and Pest](testing/phpunit.md), [Test isolation](testing/isolation.md)
- **[Compose overrides](overrides.md)**: Changing the services' Docker Compose configuration and environment variables.
- **[Disposable and external PDSes](disposable-pds.md)**: PDS containers a test owns outright, alongside the shared testnet.
- **[API reference](reference/README.md)**: [Testnet](reference/testnet.md), [PLC service](reference/plc.md), [PDS service](reference/pds.md), [Relay service](reference/relay.md)

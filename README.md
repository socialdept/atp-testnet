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

## What is ATP Testnet?

**ATP Testnet** spins up a complete local AT Protocol network using Docker Compose for integration testing. No mocks, no fakes — real PLC directory, real PDS, real relay with firehose.

## Quick Example

```php
use SocialDept\AtpTestnet\Testnet;

// Boot the network
$testnet = Testnet::start();

// Create an account
$alice = $testnet->createAccount('alice');
echo $alice->did;    // did:plc:abc123...
echo $alice->handle; // alice.test

// Create a record
$testnet->pds()->createRecord(
    'app.bsky.feed.post',
    ['$type' => 'app.bsky.feed.post', 'text' => 'Hello from testnet!', 'createdAt' => gmdate('c')],
    $alice->accessJwt,
);

// Query the PLC directory
$doc = $testnet->plc()->getDocument($alice->did);

// Subscribe to the firehose
$testnet->requestRelayCrawl();
$frames = $testnet->relay()->consumeFirehose(timeoutMs: 3000);

// Tear down
$testnet->stop();
```

## Installation

```bash
composer require socialdept/atp-testnet --dev
```

## Requirements

- PHP 8.3+
- Docker & Docker Compose
- ~2 GB disk for Docker images on first build

## Building Docker Images

The PLC directory and relay are built from source since they don't publish official Docker images. Images are built automatically on the first `Testnet::start()` call, or you can pre-build them:

```bash
# Build missing images (skips if already built)
composer testnet:build

# Force rebuild all images
composer testnet:rebuild

# Or use the binary directly
vendor/bin/testnet-build
vendor/bin/testnet-build --rebuild
```

The first build clones the repos and compiles Go and Node — this takes a few minutes. After that, starts are instant.

The relay image is patched during build to work in Docker's internal network (SSRF bypass, hostname validation, domain ban checks, and account host matching are all disabled via the `RELAY_DISABLE_SSRF` environment variable).

## Services

| Service | Image | Default Port | Purpose |
|---------|-------|-------------|---------|
| PLC Directory | Built from [did-method-plc](https://github.com/did-method-plc/did-method-plc) | 7100 | DID registration and PLC operations |
| PDS | `ghcr.io/bluesky-social/pds:0.4` | 7102 | Account creation, repos, XRPC |
| Relay | Built from [indigo](https://github.com/bluesky-social/indigo) | 7101 | Firehose relay, crawls PDS |

Each service also runs its own Postgres instance internally.

## Usage with PHPUnit / Pest

```php
use SocialDept\AtpTestnet\Concerns\UsesTestnet;

// PHPUnit
class MyTest extends TestCase
{
    use UsesTestnet;

    public function test_account_creation(): void
    {
        $account = $this->testnet->createAccount('bob');
        $this->assertStringStartsWith('did:plc:', $account->did);
    }
}

// Pest
uses(UsesTestnet::class);

test('can create account', function () {
    $account = $this->testnet->createAccount('bob');
    expect($account->did)->toStartWith('did:plc:');
});
```

The `UsesTestnet` trait boots the testnet once per test class and tears it down after all tests complete.

## Test Isolation

The testnet accumulates state across tests (accounts, DIDs, relay subscriptions). Use the reset methods to start each test with a clean slate:

```php
// Reset everything before each test
beforeEach(function () {
    $this->testnet->resetAll();
});

// Or reset individual services
beforeEach(function () {
    $this->testnet->resetPds();   // Clear accounts and repos only
});
```

| Method | What it clears |
|--------|---------------|
| `resetPds()` | All accounts, repos, sessions, invite codes (SQLite truncate) |
| `resetPlc()` | All DIDs and operations (Postgres truncate) |
| `resetRelay()` | All host subscriptions, account tracking, persisted data |
| `resetAll()` | All of the above |

Services stay running and healthy after reset — no container restarts needed.

## Configuration

```php
use SocialDept\AtpTestnet\TestnetConfig;
use SocialDept\AtpTestnet\Testnet;

$testnet = Testnet::start(new TestnetConfig(
    plcPort: 8100,
    pdsPort: 8102,
    relayPort: 8101,
    adminPassword: 'custom-admin',
));
```

## API Reference

### Testnet

```php
// Lifecycle
Testnet::start(?TestnetConfig $config = null): Testnet
$testnet->stop(): void
$testnet->isRunning(): bool

// Accounts
$testnet->createAccount(string $handle, ?string $email = null): TestAccount
$testnet->createAccountWithSession(string $handle, ?string $email = null): TestAccount
$testnet->authenticatedClient(TestAccount $account): \GuzzleHttp\Client

// Services
$testnet->plc(): PlcService
$testnet->pds(): PdsService
$testnet->relay(): RelayService

// Relay
$testnet->requestRelayCrawl(): void

// Reset (for test isolation)
$testnet->resetPds(): void       // Truncate all PDS accounts and repos
$testnet->resetPlc(): void       // Truncate all DIDs and operations
$testnet->resetRelay(): void     // Truncate relay subscriptions and data
$testnet->resetAll(): void       // Reset PDS + PLC + Relay

// Config
$testnet->config(): TestnetConfig
$testnet->rotationKeypair(): Secp256k1Keypair
```

### PLC Service

```php
// DID documents
$testnet->plc()->getDocument(string $did): array
$testnet->plc()->getDocumentData(string $did): array
$testnet->plc()->getLastOperation(string $did): array
$testnet->plc()->getOperationLog(string $did): array
$testnet->plc()->getAuditLog(string $did): array
$testnet->plc()->submitOperation(string $did, array $operation): void

// PLC operations (requires atp-cbor for signing)
$testnet->plc()->updateServiceEndpoint(string $did, string $newEndpoint, Secp256k1Keypair $signer): void
$testnet->plc()->updateHandle(string $did, string $newHandle, Secp256k1Keypair $signer): void
$testnet->plc()->updateRotationKeys(string $did, array $newRotationKeys, Secp256k1Keypair $signer): void

$testnet->plc()->isHealthy(): bool
```

### PDS Service

```php
// Accounts
$testnet->pds()->createAccount(string $handle, ?string $email = null, ?string $password = null): TestAccount
$testnet->pds()->deleteAccount(string $did, string $password, string $token): array
$testnet->pds()->deactivateAccount(string $accessJwt): array
$testnet->pds()->activateAccount(string $accessJwt): array

// Sessions
$testnet->pds()->createSession(string $identifier, string $password): array
$testnet->pds()->getSession(string $accessJwt): array
$testnet->pds()->refreshSession(string $refreshJwt): array
$testnet->pds()->deleteSession(string $refreshJwt): void

// Records
$testnet->pds()->createRecord(string $collection, array $record, string $accessJwt, ?string $repo = null): array
$testnet->pds()->getRecord(string $repo, string $collection, string $rkey): array
$testnet->pds()->deleteRecord(string $collection, string $rkey, string $accessJwt, ?string $repo = null): array
$testnet->pds()->listRecords(string $repo, string $collection, int $limit = 50): array

// Repos and blobs
$testnet->pds()->describeRepo(string $repo): array
$testnet->pds()->uploadBlob(string $data, string $mimeType, string $accessJwt): array
$testnet->pds()->getRepo(string $did): string

// Identity
$testnet->pds()->resolveHandle(string $handle): array
$testnet->pds()->updateHandle(string $handle, string $accessJwt): array

// Admin
$testnet->pds()->createInviteCode(int $useCount = 1, ?string $forAccount = null): array
$testnet->pds()->getAccountInfo(string $did): array
$testnet->pds()->adminUpdateHandle(string $did, string $handle): array
$testnet->pds()->adminUpdateEmail(string $did, string $email): array
$testnet->pds()->updateSubjectStatus(string $did, string $deactivated = 'false'): array

$testnet->pds()->describeServer(): array
$testnet->pds()->isHealthy(): bool
```

### Relay Service

```php
$testnet->relay()->requestCrawl(string $pdsHostname): void
$testnet->relay()->listRepos(?int $limit = null, ?string $cursor = null): array
$testnet->relay()->listHosts(?int $limit = null): array
$testnet->relay()->getRepoStatus(string $did): array
$testnet->relay()->getLatestCommit(string $did): array
$testnet->relay()->getBlob(string $did, string $cid): string
$testnet->relay()->consumeFirehose(int $timeoutMs = 3000, ?int $cursor = null): array

$testnet->relay()->isHealthy(): bool
```

### TestAccount

```php
$account->did;        // did:plc:abc123...
$account->handle;     // alice.test
$account->email;      // alice@test.invalid
$account->password;   // auto-generated
$account->accessJwt;  // session token
$account->refreshJwt; // refresh token
```

## Contributing

Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.

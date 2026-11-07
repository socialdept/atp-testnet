# Testnet

Part of the [atp-testnet docs](../introduction.md#documentation). Lifecycle, accounts, services, resets and spawned PDSes.

```php
// Lifecycle
Testnet::start(?TestnetConfig $config = null): Testnet
$testnet->stop(): void
$testnet->isRunning(): bool          // true when the PLC directory and the PDS are healthy

// Accounts
$testnet->createAccount(string $handle, ?string $email = null): TestAccount
$testnet->createAccountWithSession(string $handle, ?string $email = null): TestAccount
$testnet->authenticatedClient(TestAccount $account): \GuzzleHttp\Client

// Services
$testnet->plc(): PlcService
$testnet->pds(): PdsService
$testnet->relay(): RelayService

// Relay
$testnet->requestRelayCrawl(?string $pdsHostname = null): void  // null = built-in testnet PDS

// Bring-your-own or disposable PDS (shares the testnet PLC and relay)
$testnet->plcUrlForContainers(): string   // PLC URL reachable from a container you run yourself
$testnet->networkName(): string           // Compose network to attach an external container to
$testnet->spawnPds(PdsSpec $spec): SpawnedPds
$testnet->despawnPds(SpawnedPds|string $pds): void

// Reset (for test isolation)
$testnet->resetPds(): void       // Clear all PDS accounts and repos
$testnet->resetPlc(): void       // Truncate all DIDs and operations
$testnet->resetRelay(): void     // Truncate relay subscriptions and data
$testnet->resetAll(): void       // Reset the PDS, PLC and relay

// Config
$testnet->config(): TestnetConfig
$testnet->rotationKeypair(): Secp256k1Keypair
```

`createAccount()` adds `.test` to a handle with no dot in it, so `createAccount('alice')` creates `alice.test`. Its `TestAccount` already carries a session, so `createAccountWithSession()` returns the same thing.

`authenticatedClient()` returns a Guzzle client for the built-in PDS with the account's access token as a bearer token.

See [Test isolation](../testing/isolation.md) for what each reset clears, and [Disposable and external PDSes](../disposable-pds.md) for spawning a PDS.

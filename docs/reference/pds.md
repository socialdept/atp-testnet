# PDS service

Part of the [atp-testnet docs](../introduction.md#documentation). Accounts, sessions, records, blobs, identity and admin calls, plus the `TestAccount` it returns.

```php
// Handles, "local.{service handle domain}"
$testnet->pds()->handle(string $local): string   // built-in PDS: "local.test"
$spawned->pds()->handle(string $local): string   // spawned PDS: "local.{its hostname}"

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

`PdsService::createAccount()` takes the full handle. When `email` or `password` is left out, it uses `{handle}@test.invalid` and a random password. When `repo` is left out of `createRecord()` or `deleteRecord()`, the record goes to the account the access token belongs to. The admin calls use the PDS's admin password.

## TestAccount

```php
$account->did;        // did:plc:...
$account->handle;     // alice.test
$account->email;      // alice.test@test.invalid
$account->password;   // generated
$account->accessJwt;  // session token
$account->refreshJwt; // refresh token
```

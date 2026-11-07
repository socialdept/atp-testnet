# PLC service

Part of the [atp-testnet docs](../introduction.md#documentation). DID documents, operation logs and signed PLC operations.

```php
// DID documents
$testnet->plc()->getDocument(string $did): array
$testnet->plc()->getDocumentData(string $did): array
$testnet->plc()->getLastOperation(string $did): array
$testnet->plc()->getOperationLog(string $did): array
$testnet->plc()->getAuditLog(string $did): array
$testnet->plc()->submitOperation(string $did, array $operation): void

// PLC operations, signed with atp-cbor
$testnet->plc()->updateServiceEndpoint(string $did, string $newEndpoint, Secp256k1Keypair $signer): void
$testnet->plc()->updateHandle(string $did, string $newHandle, Secp256k1Keypair $signer): void
$testnet->plc()->updateRotationKeys(string $did, array $newRotationKeys, Secp256k1Keypair $signer): void

$testnet->plc()->isHealthy(): bool
```

Accounts created on the testnet PDS are controlled by its rotation key, so sign their operations with `$testnet->rotationKeypair()`:

```php
$testnet->plc()->updateHandle($account->did, 'newhandle.test', $testnet->rotationKeypair());
```

Each update reads the DID's last operation, builds the next one on top of it, signs it and submits it.

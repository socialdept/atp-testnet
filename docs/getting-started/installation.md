# Installation

Part of the [atp-testnet docs](../introduction.md#documentation). Installing the package, what it needs from Docker, and the config options.

## Installing

```bash
composer require socialdept/atp-testnet --dev
```

The testnet runs in Docker, so Docker and the Compose plugin have to be installed and running. `Testnet::start()` checks for both `docker` and `docker compose` and throws when either is missing.

The PLC directory and relay images are built from source the first time the testnet starts. See [Docker images](images.md) to build them ahead of time.

## Configuration

`Testnet::start()` takes an optional `TestnetConfig`. Every argument has a default, so pass only what you want to change:

```php
use SocialDept\AtpTestnet\Testnet;
use SocialDept\AtpTestnet\TestnetConfig;

$testnet = Testnet::start(new TestnetConfig(
    plcPort: 8100,
    pdsPort: 8102,
    relayPort: 8101,
    adminPassword: 'custom-admin',
));
```

| Argument | Default | Description |
|----------|---------|-------------|
| `plcPort` | `7100` | Host port for the PLC directory |
| `relayPort` | `7101` | Host port for the relay |
| `pdsPort` | `7102` | Host port for the PDS |
| `adminPassword` | `testnet-admin` | PDS admin password |
| `jwtSecret` | `testnet-jwt-secret` | PDS JWT secret |
| `projectName` | `atp-testnet` | Docker Compose project name, which also prefixes the container and network names |
| `plcRotationKeyHex` | a fixed secp256k1 key | The PDS's PLC rotation key, as hex |
| `composeOverride` | `null` | Path to a Compose override file, see [Compose overrides](../overrides.md) |

The services are reached on `http://localhost:{port}`. `$testnet->rotationKeypair()` returns the rotation key as a keypair, for signing [PLC operations](../reference/plc.md).

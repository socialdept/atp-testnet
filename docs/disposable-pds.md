# Disposable and external PDSes

Part of the [atp-testnet docs](introduction.md#documentation). PDS containers a test owns outright, alongside the shared testnet.

The shared testnet PDS uses fixed secrets and is reused across tests. That suits PLC, account and firehose assertions, but you cannot rotate its secrets or rebuild its container without breaking other tests. For tests that need a PDS they fully own, such as secret rotation, container rebuilds or migrating between PDSes, spawn a disposable one. Its DIDs still register in the shared testnet PLC directory:

```php
use SocialDept\AtpTestnet\Data\PdsSpec;

$spawned = $testnet->spawnPds(new PdsSpec(name: 'my-pds', hostPort: 7300));

$pds = $spawned->pds();
$account = $pds->createAccount($pds->handle('alice')); // alice.my-pds.test
$doc = $testnet->plc()->getDocument($account->did);     // resolves on the shared PLC

$testnet->despawnPds($spawned); // or $testnet->stop(), which tears down every spawned PDS
```

`spawnPds()` runs the container with `docker run`, publishes it on `127.0.0.1:{hostPort}`, and waits up to 60 seconds for it to answer its health check. `$spawned->pds()` returns a [PDS service](reference/pds.md) with admin access to it.

## The spec

Only `name` and `hostPort` are required. `name` is also the container name.

| Argument | Default | Description |
|----------|---------|-------------|
| `image` | `ghcr.io/bluesky-social/pds:0.4` | The PDS image |
| `dataPath` | `null` | A host path bind-mounted at `/pds/data`, with blobs at `{dataPath}-blobs`. It survives container rebuilds. Without one, the PDS uses the volumes `{name}-data` and `{name}-blobs` |
| `adminPassword` | generated | The admin password |
| `jwtSecret` | generated | The JWT secret |
| `rotationKeyHex` | generated | The PLC rotation key, as hex |
| `hostname` | `{name}.test` | The hostname, which is also the handle domain |
| `network` | `null` | A Docker network to attach, such as `$testnet->networkName()` |
| `plcUrl` | see below | The PLC directory URL the container uses |
| `extraEnv` | `[]` | More environment variables, merged last |

Generated secrets keep the PDS isolated from the shared one. Pass explicit secrets and a `hostname` when a consumer rebuilds the container itself and has to reproduce its exact configuration. The returned `SpawnedPds` carries the values in effect: `containerName`, `hostPort`, `url`, `hostname`, `adminPassword`, `jwtSecret`, `rotationKeyHex` and `dataPath`.

When `plcUrl` is not given, a PDS on a `network` uses `http://plc:3000`, and one without uses `$testnet->plcUrlForContainers()`.

> [!WARNING]
> `plcUrlForContainers()` and the relay address a spawned PDS crawls through use `host.docker.internal`, which Docker Desktop provides but Linux hosts do not by default. This is why the package's own spawn tests are in the `integration` group that CI skips.

`despawnPds()` takes the `SpawnedPds` or its container name. It removes the container, and the managed volumes when no `dataPath` was given.

## Bringing your own PDS

To run a PDS container entirely yourself against the shared testnet, point its `PDS_DID_PLC_URL` at `$testnet->plcUrlForContainers()`. Optionally, call `$testnet->requestRelayCrawl($yourPdsHostname)` to have the relay index it. To reach the services by name instead, such as `http://plc:3000`, attach the container to `$testnet->networkName()`.

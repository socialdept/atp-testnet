# Services

Part of the [atp-testnet docs](../introduction.md#documentation). The containers the testnet runs and the ports they listen on.

| Service | Image | Default port | Purpose |
|---------|-------|--------------|---------|
| PLC directory | `atp-testnet-plc:latest`, built from [did-method-plc](https://github.com/did-method-plc/did-method-plc) | 7100 | DID registration and PLC operations |
| Relay | `atp-testnet-relay:latest`, built from [indigo](https://github.com/bluesky-social/indigo) | 7101 | Firehose relay that crawls the PDS |
| PDS | `ghcr.io/bluesky-social/pds:0.4` | 7102 | Accounts, repos and XRPC |

The PLC directory and the relay each have their own `postgres:16` container. The PDS keeps its data in SQLite. The ports are set by [`TestnetConfig`](installation.md#configuration).

The PDS serves handles under `.test`, so `createAccount('alice')` creates `alice.test`. Inside the Compose network the services reach each other by name: the PLC directory at `http://plc:3000`, the PDS at `http://pds:3000` and the relay at `http://relay:2470`.

`Testnet::start()` brings the services up and waits until the PLC directory, the PDS and the relay all answer their health checks. `$testnet->stop()` takes them down and removes their volumes, along with any [spawned PDS](../disposable-pds.md).

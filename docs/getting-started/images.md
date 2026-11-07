# Docker images

Part of the [atp-testnet docs](../introduction.md#documentation). Building the PLC and relay images from source, and the patches applied to them.

The PLC directory and relay do not publish official Docker images, so the package builds them from source. `Testnet::start()` builds any image that is missing locally, or you can build them ahead of time with the package's binary:

```bash
# Build missing images (skips any already built)
vendor/bin/testnet-build

# Force a rebuild of every image
vendor/bin/testnet-build --rebuild
```

When working on this package itself, `composer testnet:build` and `composer testnet:rebuild` run the same commands.

| Image | Built from |
|-------|------------|
| `atp-testnet-plc:latest` | [did-method-plc](https://github.com/did-method-plc/did-method-plc) |
| `atp-testnet-relay:latest` | [indigo](https://github.com/bluesky-social/indigo), `cmd/relay` |

The first build clones both repos and compiles the Node and Go services, which takes a few minutes. After that, starts reuse the images. The clones are kept in `atp-testnet-builds` under the system temp directory.

## Patches

The sources are patched before each build.

The relay is patched to work inside Docker's internal network. With the `RELAY_DISABLE_SSRF` environment variable set to `1`, it skips SSRF protection and TLS verification, accepts any hostname, skips domain ban checks and skips the account host check.

The PLC directory is patched so its operation rate limit (10 operations an hour per DID in production) can be turned off with the `PLC_DISABLE_RATE_LIMIT` environment variable. The testnet's compose file sets both variables. See [Compose overrides](../overrides.md) to change them.

A patch fails loudly when the upstream source no longer matches it. If that happens, delete the build cache and rebuild.

If you update the package and its build-time patches change, force a rebuild with `vendor/bin/testnet-build --rebuild`.

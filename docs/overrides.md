# Compose overrides

Part of the [atp-testnet docs](introduction.md#documentation). Changing the services' Docker Compose configuration and environment variables.

To change how the services run, put an override file in your project root. Start from the stub the package ships:

```bash
cp vendor/socialdept/atp-testnet/stubs/docker-compose.testnet.yml docker-compose.testnet.yml
```

Then set only the values you want to change:

```yaml
# docker-compose.testnet.yml
services:
  plc:
    environment:
      PLC_DISABLE_RATE_LIMIT: ""  # Re-enable rate limits
  pds:
    environment:
      PDS_DEV_MODE: "0"
      PDS_HOSTNAME: custom.test
```

The testnet looks for `docker-compose.testnet.yml` in the working directory and passes it to Docker Compose as a second `-f` file, merged on top of the base configuration. Everything you leave out keeps its default.

> [!NOTE]
> Overrides apply when the services start, so a change takes effect on the next `Testnet::start()` with no image rebuild. Images only need a rebuild when the package changes its [build-time patches](getting-started/images.md#patches).

You can also pass an explicit path in the config:

```php
$testnet = Testnet::start(new TestnetConfig(
    composeOverride: __DIR__.'/testnet-overrides.yml',
));
```

When the explicit path does not exist, no override is used. `TestnetConfig::stubPath()` returns the path to the stub.

## Environment variables

| Variable | Service | Default | Description |
|----------|---------|---------|-------------|
| `PLC_DISABLE_RATE_LIMIT` | PLC | `1` | Turns off the PLC directory's operation rate limit while set to any non-empty value |
| `RELAY_DISABLE_SSRF` | Relay | `1` | Turns off SSRF protection and the related checks, so the relay can reach containers on the Docker network |
| `RELAY_ALLOW_INSECURE_HOSTS` | Relay | `1` | Allow HTTP (non-TLS) host connections |
| `PDS_DEV_MODE` | PDS | `1` | Allow HTTP hostnames, disable SSRF protection |
| `PDS_INVITE_REQUIRED` | PDS | `false` | Require invite codes for account creation |
| `PDS_HOSTNAME` | PDS | `pds.test` | The PDS's hostname |

> [!IMPORTANT]
> The PLC patch checks only that `PLC_DISABLE_RATE_LIMIT` is set, not its value, so `"0"` still turns the limit off. Set it to an empty string to turn the limit back on.

`createAccount()` and `pds()->handle()` build handles under `.test` whatever `PDS_HOSTNAME` is set to.

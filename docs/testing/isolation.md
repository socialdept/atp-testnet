# Test isolation

Part of the [atp-testnet docs](../introduction.md#documentation). Resetting the PDS, the PLC directory and the relay between tests.

The testnet keeps its state across tests: accounts, DIDs and relay subscriptions. Use the reset methods to start each test with a clean slate:

```php
// Reset everything before each test
beforeEach(function () {
    $this->testnet->resetAll();
});

// Or reset one service
beforeEach(function () {
    $this->testnet->resetPds();
});
```

| Method | What it clears |
|--------|----------------|
| `resetPds()` | Every table in the PDS account database (accounts, sessions, invite codes and the rest), the repo sequencer and the per-account repo files |
| `resetPlc()` | All DIDs, operations and admin logs in the PLC directory's Postgres |
| `resetRelay()` | All hosts, accounts and domain bans in the relay's Postgres, and its persisted data directory |
| `resetAll()` | All of the above |

The services keep running through a reset, so no container restarts.

A reset works on the containers by name, from the Compose project name (`atp-testnet-pds-1` and so on). Each throws a `RuntimeException` when its database command fails.

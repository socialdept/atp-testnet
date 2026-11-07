# Relay service

Part of the [atp-testnet docs](../introduction.md#documentation). Crawls, repos, hosts, blobs and the firehose.

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

`requestCrawl()` goes through the relay's admin endpoint. `$testnet->requestRelayCrawl()` calls it with the built-in PDS's internal address, `http://pds:3000`.

`consumeFirehose()` connects to `com.atproto.sync.subscribeRepos`, collects frames for `timeoutMs` milliseconds and returns them raw, as binary header and body. Pass a `cursor` to start from a sequence number instead of the latest event.

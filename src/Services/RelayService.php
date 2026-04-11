<?php

declare(strict_types=1);

namespace SocialDept\AtpTestnet\Services;

use GuzzleHttp\Client;

class RelayService
{
    private Client $client;

    private string $baseUrl;

    public function __construct(
        string $baseUrl,
        private readonly string $adminPassword = 'testnet-relay-admin',
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => 10,
        ]);
    }

    /**
     * Request the relay to crawl a PDS via the admin endpoint.
     */
    public function requestCrawl(string $pdsHostname): void
    {
        $this->client->post('/admin/pds/requestCrawl', [
            'json' => ['hostname' => $pdsHostname],
            'auth' => ['admin', $this->adminPassword],
        ]);
    }

    /**
     * List repos the relay knows about.
     *
     * @return array<string, mixed>
     */
    public function listRepos(?int $limit = null, ?string $cursor = null): array
    {
        $query = [];
        if ($limit) {
            $query['limit'] = $limit;
        }
        if ($cursor) {
            $query['cursor'] = $cursor;
        }

        $response = $this->client->get('/xrpc/com.atproto.sync.listRepos', [
            'query' => $query ?: null,
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * List hosts the relay knows about.
     *
     * @return array<string, mixed>
     */
    public function listHosts(?int $limit = null): array
    {
        $query = [];
        if ($limit) {
            $query['limit'] = $limit;
        }

        $response = $this->client->get('/xrpc/com.atproto.sync.listHosts', [
            'query' => $query ?: null,
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Get repo status on the relay.
     *
     * @return array<string, mixed>
     */
    public function getRepoStatus(string $did): array
    {
        $response = $this->client->get('/xrpc/com.atproto.sync.getRepoStatus', [
            'query' => ['did' => $did],
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Get the latest commit for a repo.
     *
     * @return array<string, mixed>
     */
    public function getLatestCommit(string $did): array
    {
        $response = $this->client->get('/xrpc/com.atproto.sync.getLatestCommit', [
            'query' => ['did' => $did],
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Get a blob from the relay.
     */
    public function getBlob(string $did, string $cid): string
    {
        $response = $this->client->get('/xrpc/com.atproto.sync.getBlob', [
            'query' => ['did' => $did, 'cid' => $cid],
        ]);

        return $response->getBody()->getContents();
    }

    /**
     * Subscribe to the firehose and collect events for a duration.
     *
     * @param  int  $timeoutMs  How long to listen (milliseconds)
     * @param  int|null  $cursor  Sequence number to start from (null = latest)
     * @return array<int, array<string, mixed>>  Raw firehose frames (header + body as binary)
     */
    public function consumeFirehose(int $timeoutMs = 3000, ?int $cursor = null): array
    {
        $url = str_replace('http://', 'ws://', $this->baseUrl)
            . '/xrpc/com.atproto.sync.subscribeRepos'
            . ($cursor !== null ? "?cursor={$cursor}" : '');

        $ws = new \WebSocket\Client($url, [
            'timeout' => (int) ceil($timeoutMs / 1000) + 1,
        ]);

        $frames = [];
        $start = microtime(true);
        $deadline = $start + ($timeoutMs / 1000);

        try {
            while (microtime(true) < $deadline) {
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) {
                    break;
                }

                try {
                    $message = $ws->receive();
                    $frames[] = $message;
                } catch (\WebSocket\TimeoutException) {
                    break;
                }
            }
        } catch (\Throwable) {
            // Connection closed or error, return what we have
        } finally {
            try {
                $ws->close();
            } catch (\Throwable) {
            }
        }

        return $frames;
    }

    /**
     * Health check.
     */
    public function isHealthy(): bool
    {
        try {
            $response = $this->client->get('/xrpc/_health');

            return $response->getStatusCode() === 200;
        } catch (\Throwable) {
            return false;
        }
    }
}

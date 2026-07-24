<?php

declare(strict_types=1);

namespace SocialDept\AtpTestnet;

use RuntimeException;
use SocialDept\AtpTestnet\Data\PdsSpec;
use SocialDept\AtpTestnet\Data\SpawnedPds;
use SocialDept\AtpTestnet\Data\TestAccount;
use SocialDept\AtpTestnet\Services\PdsService;
use SocialDept\AtpTestnet\Services\PlcService;
use SocialDept\AtpTestnet\Services\RelayService;
use Symfony\Component\Process\Process;

class Testnet
{
    private PlcService $plcService;

    private PdsService $pdsService;

    private RelayService $relayService;

    /** @var array<string, SpawnedPds> Disposable PDSes spawned via spawnPds(), by container name. */
    private array $spawnedPdses = [];

    private function __construct(
        private readonly TestnetConfig $config,
    ) {
        $this->plcService = new PlcService($config->plcUrl());
        $this->pdsService = new PdsService($config->pdsUrl(), $config->adminPassword, TestnetConfig::PDS_HANDLE_DOMAIN);
        $this->relayService = new RelayService($config->relayUrl());
    }

    /**
     * Start the testnet. Builds images from source if not available locally.
     */
    public static function start(?TestnetConfig $config = null): self
    {
        $config ??= new TestnetConfig();

        self::requireDocker();

        // Build images from source if not available locally
        (new ImageBuilder())->buildAll();

        $instance = new self($config);
        $instance->composeUp();
        $instance->waitForHealth();

        return $instance;
    }

    /**
     * Verify Docker and Docker Compose are available.
     */
    private static function requireDocker(): void
    {
        $docker = new Process(['docker', '--version']);
        $docker->run();

        if (! $docker->isSuccessful()) {
            throw new RuntimeException(
                "Docker is required but not found. Install: https://docs.docker.com/get-docker/"
            );
        }

        $compose = new Process(['docker', 'compose', 'version']);
        $compose->run();

        if (! $compose->isSuccessful()) {
            throw new RuntimeException(
                "Docker Compose is required but not found. It ships with Docker Desktop."
            );
        }
    }

    /**
     * Stop the testnet and remove volumes.
     */
    public function stop(): void
    {
        foreach (array_keys($this->spawnedPdses) as $containerName) {
            $this->despawnPds($containerName);
        }

        $this->runCompose(['down', '-v', '--remove-orphans']);
    }

    /**
     * Create an account on the PDS.
     */
    public function createAccount(string $handle, ?string $email = null): TestAccount
    {
        $fullHandle = str_contains($handle, '.') ? $handle : "{$handle}.".TestnetConfig::PDS_HANDLE_DOMAIN;

        return $this->pdsService->createAccount($fullHandle, $email);
    }

    public function plc(): PlcService
    {
        return $this->plcService;
    }

    public function pds(): PdsService
    {
        return $this->pdsService;
    }

    public function relay(): RelayService
    {
        return $this->relayService;
    }

    /**
     * Create an account and return it with a fresh session.
     */
    public function createAccountWithSession(string $handle, ?string $email = null): TestAccount
    {
        $account = $this->createAccount($handle, $email);

        // Session is already included from createAccount response
        return $account;
    }

    /**
     * Get an authenticated Guzzle client for a test account.
     */
    public function authenticatedClient(TestAccount $account): \GuzzleHttp\Client
    {
        return new \GuzzleHttp\Client([
            'base_uri' => $this->config->pdsUrl(),
            'timeout' => 15,
            'headers' => [
                'Authorization' => "Bearer {$account->accessJwt}",
            ],
        ]);
    }

    /**
     * Get the PLC rotation keypair used by the PDS.
     * Use this to sign PLC operations for accounts created on the testnet PDS.
     */
    public function rotationKeypair(): \SocialDept\AtpCbor\Crypto\Secp256k1Keypair
    {
        return $this->config->rotationKeypair();
    }

    /**
     * Request the relay to crawl a PDS. Defaults to the built-in testnet PDS
     * via its internal Docker hostname; pass a hostname to crawl a
     * consumer-owned or spawned PDS instead.
     */
    public function requestRelayCrawl(?string $pdsHostname = null): void
    {
        $this->relayService->requestCrawl($pdsHostname ?? 'http://pds:3000');
    }

    /**
     * PLC directory URL reachable from a container the consumer runs itself
     * (i.e. not part of this compose project). On Docker Desktop the host's
     * published PLC port is reachable via host.docker.internal.
     *
     * Use this for the PDS env var PDS_DID_PLC_URL when bringing your own PDS
     * container, so its DIDs register in the shared testnet PLC.
     */
    public function plcUrlForContainers(): string
    {
        return "http://host.docker.internal:{$this->config->plcPort}";
    }

    /**
     * The compose project's default Docker network. Attach a consumer-owned
     * container to this network to reach services by name (e.g. http://plc:3000).
     */
    public function networkName(): string
    {
        return "{$this->config->projectName}_default";
    }

    /**
     * Launch a disposable PDS container, isolated from the shared testnet PDS,
     * registering its DIDs in the shared testnet PLC.
     *
     * The consumer owns the returned container's lifecycle thereafter (it may
     * rebuild it with rotated secrets, etc.); call despawnPds() — or stop() —
     * to tear it down.
     */
    public function spawnPds(PdsSpec $spec): SpawnedPds
    {
        $hostname = $spec->resolvedHostname();
        $adminPassword = $spec->resolvedAdminPassword();
        $jwtSecret = $spec->resolvedJwtSecret();
        $rotationKeyHex = $spec->resolvedRotationKeyHex();
        $plcUrl = $spec->plcUrl
            ?? ($spec->network !== null ? 'http://plc:3000' : $this->plcUrlForContainers());

        $dataMount = $spec->dataPath ?? "{$spec->name}-data";
        $blobMount = $spec->dataPath !== null ? "{$spec->dataPath}-blobs" : "{$spec->name}-blobs";

        $env = [
            'PDS_HOSTNAME' => $hostname,
            'PDS_PORT' => '3000',
            'PDS_DEV_MODE' => 'true',
            'PDS_INVITE_REQUIRED' => 'false',
            'PDS_DATA_DIRECTORY' => '/pds/data',
            'PDS_BLOBSTORE_DISK_LOCATION' => '/pds/blobs',
            'PDS_SERVICE_HANDLE_DOMAINS' => ".{$hostname}",
            'PDS_JWT_SECRET' => $jwtSecret,
            'PDS_ADMIN_PASSWORD' => $adminPassword,
            'PDS_PLC_ROTATION_KEY_K256_PRIVATE_KEY_HEX' => $rotationKeyHex,
            'PDS_DID_PLC_URL' => $plcUrl,
            'PDS_BSKY_APP_VIEW_URL' => 'https://api.bsky.app',
            'PDS_BSKY_APP_VIEW_DID' => 'did:web:api.bsky.app',
            'PDS_REPORT_SERVICE_URL' => 'https://mod.bsky.app',
            'PDS_REPORT_SERVICE_DID' => 'did:plc:ar7c4by46qjdydhdevvrndac',
            'PDS_CRAWLERS' => $this->relayUrlForContainers(),
            ...$spec->extraEnv,
        ];

        $command = [
            'docker', 'run', '-d',
            '--name', $spec->name,
            '-p', "127.0.0.1:{$spec->hostPort}:3000",
            '-v', "{$dataMount}:/pds/data",
            '-v', "{$blobMount}:/pds/blobs",
        ];

        if ($spec->network !== null) {
            $command[] = '--network';
            $command[] = $spec->network;
        }

        foreach ($env as $key => $value) {
            $command[] = '-e';
            $command[] = "{$key}={$value}";
        }

        $command[] = $spec->image;

        $run = new Process($command);
        $run->setTimeout(120);
        $run->run();

        if (! $run->isSuccessful()) {
            throw new RuntimeException(
                "Failed to spawn PDS '{$spec->name}': {$run->getErrorOutput()}"
            );
        }

        $url = "http://localhost:{$spec->hostPort}";
        $this->waitForSpawnedPdsHealth($url, $adminPassword);

        $spawned = new SpawnedPds(
            containerName: $spec->name,
            hostPort: $spec->hostPort,
            url: $url,
            hostname: $hostname,
            adminPassword: $adminPassword,
            jwtSecret: $jwtSecret,
            rotationKeyHex: $rotationKeyHex,
            dataPath: $spec->dataPath,
        );

        $this->spawnedPdses[$spec->name] = $spawned;

        return $spawned;
    }

    /**
     * Tear down a spawned PDS. Removes the container and, when it used a
     * managed volume (no host dataPath), the managed data/blob volumes.
     */
    public function despawnPds(SpawnedPds|string $pds): void
    {
        $name = $pds instanceof SpawnedPds ? $pds->containerName : $pds;
        $spawned = $this->spawnedPdses[$name] ?? ($pds instanceof SpawnedPds ? $pds : null);

        (new Process(['docker', 'rm', '-f', $name]))->run();

        if ($spawned !== null && $spawned->dataPath === null) {
            (new Process(['docker', 'volume', 'rm', '-f', "{$name}-data", "{$name}-blobs"]))->run();
        }

        unset($this->spawnedPdses[$name]);
    }

    /**
     * Relay crawl endpoint reachable from a consumer-owned container.
     */
    private function relayUrlForContainers(): string
    {
        return "http://host.docker.internal:{$this->config->relayPort}";
    }

    private function waitForSpawnedPdsHealth(string $url, string $adminPassword): void
    {
        $pds = new PdsService($url, $adminPassword);

        for ($i = 0; $i < 60; $i++) {
            if ($pds->isHealthy()) {
                return;
            }

            usleep(1_000_000);
        }

        throw new RuntimeException("Spawned PDS at {$url} did not become healthy.");
    }

    /**
     * Reset all PDS data (accounts, repos, sequences).
     * Truncates SQLite tables inside the PDS container without restarting it.
     */
    public function resetPds(): void
    {
        $container = "{$this->config->projectName}-pds-1";

        $script = <<<'JS'
            const fs = require('fs');

            // The PDS image installs with pnpm, so `better-sqlite3` is not
            // resolvable from /app by name: it lives under a version-stamped
            // directory in the pnpm store. Pinning that version meant every
            // reset broke the moment the image bumped it (10.1.0 -> 12.11.1),
            // and the failure surfaced as MODULE_NOT_FOUND on every single
            // integration test, so find the directory instead of naming it.
            const Database = (() => {
                try {
                    return require('better-sqlite3');
                } catch {
                    const store = '/app/node_modules/.pnpm';
                    const dir = fs
                        .readdirSync(store)
                        .find((name) => name.startsWith('better-sqlite3@'));

                    if (!dir) {
                        throw new Error(`better-sqlite3 not found under ${store}`);
                    }

                    return require(`${store}/${dir}/node_modules/better-sqlite3`);
                }
            })();

            // Reset account database — delete all user data, preserve schema
            const db = new Database('/pds/data/account.sqlite');
            const tables = db.prepare(
                "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite%' AND name NOT LIKE 'kysely%'"
            ).all().map(t => t.name);

            // Disable foreign keys, truncate all tables, re-enable
            db.pragma('foreign_keys = OFF');
            for (const table of tables) {
                db.exec(`DELETE FROM "${table}"`);
            }
            db.pragma('foreign_keys = ON');
            db.close();

            // Reset sequencer
            const seq = new Database('/pds/data/sequencer.sqlite');
            seq.exec('DELETE FROM repo_seq');
            seq.close();

            // Remove per-actor repo directories
            const actorsDir = '/pds/data/actors';
            if (fs.existsSync(actorsDir)) {
                fs.rmSync(actorsDir, { recursive: true, force: true });
                fs.mkdirSync(actorsDir);
            }

            console.log('reset-ok');
        JS;

        $process = new Process(['docker', 'exec', $container, 'node', '-e', $script]);
        $process->setTimeout(10);
        $process->run();

        if (! $process->isSuccessful() || ! str_contains($process->getOutput(), 'reset-ok')) {
            throw new RuntimeException(
                "Failed to reset PDS data: {$process->getErrorOutput()}"
            );
        }
    }

    /**
     * Reset the PLC directory (truncate all DIDs and operations).
     */
    public function resetPlc(): void
    {
        $container = "{$this->config->projectName}-plc_pg-1";

        $process = new Process([
            'docker', 'exec', $container,
            'psql', '-U', 'plc', '-d', 'plc', '-c',
            'TRUNCATE operations, dids, admin_logs CASCADE;',
        ]);
        $process->setTimeout(10);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                "Failed to reset PLC data: {$process->getErrorOutput()}"
            );
        }
    }

    /**
     * Reset the relay (truncate all hosts, accounts, and persisted data).
     */
    public function resetRelay(): void
    {
        $pgContainer = "{$this->config->projectName}-relay_pg-1";
        $relayContainer = "{$this->config->projectName}-relay-1";

        // Truncate relay postgres tables
        $process = new Process([
            'docker', 'exec', $pgContainer,
            'psql', '-U', 'relay', '-d', 'relay', '-c',
            'TRUNCATE account, account_repo, host, log_file_refs, domain_bans CASCADE;',
        ]);
        $process->setTimeout(10);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                "Failed to reset relay database: {$process->getErrorOutput()}"
            );
        }

        // Clear persisted data directory
        $clear = new Process([
            'docker', 'exec', $relayContainer,
            'sh', '-c', 'rm -rf /data/* 2>/dev/null; true',
        ]);
        $clear->setTimeout(5);
        $clear->run();
    }

    /**
     * Reset all services (PDS, PLC, and Relay).
     */
    public function resetAll(): void
    {
        $this->resetPds();
        $this->resetPlc();
        $this->resetRelay();
    }

    /**
     * Check if all services are healthy.
     */
    public function isRunning(): bool
    {
        return $this->plcService->isHealthy()
            && $this->pdsService->isHealthy();
    }

    /**
     * Get the config.
     */
    public function config(): TestnetConfig
    {
        return $this->config;
    }

    private function composeUp(): void
    {
        $this->runCompose(['up', '-d', '--wait']);
    }

    private function waitForHealth(int $maxAttempts = 60, int $intervalMs = 1000): void
    {
        for ($i = 0; $i < $maxAttempts; $i++) {
            if ($this->plcService->isHealthy() && $this->pdsService->isHealthy() && $this->relayService->isHealthy()) {
                return;
            }

            usleep($intervalMs * 1000);
        }

        throw new RuntimeException(
            "Testnet failed to become healthy after {$maxAttempts} attempts. "
            .'Check docker compose logs for details.'
        );
    }

    /**
     * @param  string[]  $args
     */
    private function runCompose(array $args): void
    {
        $composePath = dirname(__DIR__).'/docker/docker-compose.yml';

        $command = [
            'docker', 'compose',
            '-f', $composePath,
        ];

        $override = $this->config->resolveComposeOverride();
        if ($override) {
            $command[] = '-f';
            $command[] = $override;
        }

        $command = [
            ...$command,
            '-p', $this->config->projectName,
            ...$args,
        ];

        $process = new Process($command, env: $this->config->toEnv());
        $process->setTimeout(120);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                "Docker compose failed: {$process->getErrorOutput()}"
            );
        }
    }
}

<?php

declare(strict_types=1);

namespace SocialDept\AtpTestnet;

use RuntimeException;
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

    private function __construct(
        private readonly TestnetConfig $config,
    ) {
        $this->plcService = new PlcService($config->plcUrl());
        $this->pdsService = new PdsService($config->pdsUrl(), $config->adminPassword);
        $this->relayService = new RelayService($config->relayUrl());
    }

    /**
     * Start the testnet. Builds images from source if not available locally.
     */
    public static function start(?TestnetConfig $config = null): self
    {
        $config ??= new TestnetConfig;

        self::requireDocker();

        // Build images from source if not available locally
        (new ImageBuilder)->buildAll();

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
        $this->runCompose(['down', '-v', '--remove-orphans']);
    }

    /**
     * Create an account on the PDS.
     */
    public function createAccount(string $handle, ?string $email = null): TestAccount
    {
        $fullHandle = str_contains($handle, '.') ? $handle : "{$handle}.test";

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
     * Request the relay to crawl the testnet PDS.
     * Uses the internal Docker hostname so the relay can reach the PDS.
     */
    public function requestRelayCrawl(): void
    {
        $this->relayService->requestCrawl('http://pds:3000');
    }

    /**
     * Reset all PDS data (accounts, repos, sequences).
     * Truncates SQLite tables inside the PDS container without restarting it.
     */
    public function resetPds(): void
    {
        $container = "{$this->config->projectName}-pds-1";

        $script = <<<'JS'
            const Database = require('/app/node_modules/.pnpm/better-sqlite3@10.1.0/node_modules/better-sqlite3');
            const fs = require('fs');

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

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

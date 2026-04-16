<?php

declare(strict_types=1);

namespace SocialDept\AtpTestnet;

use SocialDept\AtpCbor\Crypto\Secp256k1Keypair;

class TestnetConfig
{
    public readonly string $plcRotationKeyHex;

    /** Default rotation key matching docker-compose.yml */
    private const DEFAULT_ROTATION_KEY = '32368eae9ee6909042912c57c5db0639b33202602938c99e8c6b094f291e4b26';

    /** Default filename for compose overrides, auto-detected from project root */
    public const COMPOSE_OVERRIDE_FILE = 'docker-compose.testnet.yml';

    public function __construct(
        public int $plcPort = 7100,
        public int $relayPort = 7101,
        public int $pdsPort = 7102,
        public string $adminPassword = 'testnet-admin',
        public string $jwtSecret = 'testnet-jwt-secret',
        public string $projectName = 'atp-testnet',
        ?string $plcRotationKeyHex = null,
        public ?string $composeOverride = null,
    ) {
        $this->plcRotationKeyHex = $plcRotationKeyHex ?? self::DEFAULT_ROTATION_KEY;
    }

    /**
     * Resolve the compose override file path.
     * Checks: explicit path → project root convention → null.
     */
    public function resolveComposeOverride(): ?string
    {
        if ($this->composeOverride) {
            return file_exists($this->composeOverride) ? $this->composeOverride : null;
        }

        // Auto-detect from working directory (project root)
        $conventional = getcwd().'/'.self::COMPOSE_OVERRIDE_FILE;

        return file_exists($conventional) ? $conventional : null;
    }

    /**
     * Get the path to the publishable stub file.
     */
    public static function stubPath(): string
    {
        return dirname(__DIR__).'/stubs/'.self::COMPOSE_OVERRIDE_FILE;
    }

    public function plcUrl(): string
    {
        return "http://localhost:{$this->plcPort}";
    }

    public function pdsUrl(): string
    {
        return "http://localhost:{$this->pdsPort}";
    }

    public function relayUrl(): string
    {
        return "http://localhost:{$this->relayPort}";
    }

    /**
     * Get the PLC rotation keypair used by the PDS.
     */
    public function rotationKeypair(): Secp256k1Keypair
    {
        return Secp256k1Keypair::fromHex($this->plcRotationKeyHex);
    }

    /**
     * Environment variables for docker compose.
     *
     * @return array<string, string>
     */
    public function toEnv(): array
    {
        return [
            'ATP_TESTNET_PLC_PORT' => (string) $this->plcPort,
            'ATP_TESTNET_RELAY_PORT' => (string) $this->relayPort,
            'ATP_TESTNET_PDS_PORT' => (string) $this->pdsPort,
            'ATP_TESTNET_ADMIN_PASSWORD' => $this->adminPassword,
            'ATP_TESTNET_JWT_SECRET' => $this->jwtSecret,
            'ATP_TESTNET_PLC_ROTATION_KEY' => $this->plcRotationKeyHex,
        ];
    }
}

<?php

declare(strict_types=1);

namespace SocialDept\AtpTestnet;

use SocialDept\AtpCbor\Crypto\Secp256k1Keypair;

class TestnetConfig
{
    public readonly string $plcRotationKeyHex;

    public function __construct(
        public int $plcPort = 7100,
        public int $relayPort = 7101,
        public int $pdsPort = 7102,
        public string $adminPassword = 'testnet-admin',
        public string $jwtSecret = 'testnet-jwt-secret',
        public string $projectName = 'atp-testnet',
        ?string $plcRotationKeyHex = null,
    ) {
        $this->plcRotationKeyHex = $plcRotationKeyHex ?? bin2hex(random_bytes(32));
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

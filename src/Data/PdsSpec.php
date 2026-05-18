<?php

declare(strict_types=1);

namespace SocialDept\AtpTestnet\Data;

/**
 * Specification for a disposable PDS container launched via Testnet::spawnPds().
 *
 * Only `name` and `hostPort` are required. Everything else defaults to
 * sensible disposable-test values; secrets are generated when omitted so a
 * spawned PDS is isolated from the shared testnet fixture.
 */
class PdsSpec
{
    public function __construct(
        public readonly string $name,
        public readonly int $hostPort,
        public readonly string $image = 'ghcr.io/bluesky-social/pds:0.4',
        /** Host path bind-mounted at /pds/data. Null = managed `{name}-data` volume. */
        public readonly ?string $dataPath = null,
        public readonly ?string $adminPassword = null,
        public readonly ?string $jwtSecret = null,
        public readonly ?string $rotationKeyHex = null,
        /** PDS hostname / handle domain. Null = "{name}.test". */
        public readonly ?string $hostname = null,
        /** Docker network to attach. Null = default bridge (reaches PLC via host.docker.internal). */
        public readonly ?string $network = null,
        /** PLC URL the container should use. Null = Testnet::plcUrlForContainers(). */
        public readonly ?string $plcUrl = null,
        /** @var array<string, string> Extra env vars, merged last. */
        public readonly array $extraEnv = [],
    ) {
    }

    public function resolvedHostname(): string
    {
        return $this->hostname ?? "{$this->name}.test";
    }

    public function resolvedAdminPassword(): string
    {
        return $this->adminPassword ?? bin2hex(random_bytes(16));
    }

    public function resolvedJwtSecret(): string
    {
        return $this->jwtSecret ?? bin2hex(random_bytes(16));
    }

    public function resolvedRotationKeyHex(): string
    {
        return $this->rotationKeyHex ?? bin2hex(random_bytes(32));
    }
}

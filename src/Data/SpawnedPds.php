<?php

declare(strict_types=1);

namespace SocialDept\AtpTestnet\Data;

use SocialDept\AtpTestnet\Services\PdsService;

/**
 * A live, disposable PDS container launched by Testnet::spawnPds().
 *
 * Carries the effective (post-default) identity + secrets so a consumer that
 * manages the container's lifecycle itself (e.g. rebuilding it with rotated
 * secrets) can reproduce its configuration exactly.
 */
class SpawnedPds
{
    public function __construct(
        public readonly string $containerName,
        public readonly int $hostPort,
        public readonly string $url,
        public readonly string $hostname,
        public readonly string $adminPassword,
        public readonly string $jwtSecret,
        public readonly string $rotationKeyHex,
        /** Host bind-mount path, or null when backed by a managed volume. */
        public readonly ?string $dataPath = null,
    ) {
    }

    /**
     * An admin-authenticated PDS client for this container.
     */
    public function pds(): PdsService
    {
        return new PdsService($this->url, $this->adminPassword, $this->hostname);
    }
}

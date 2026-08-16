<?php

declare(strict_types=1);

namespace SocialDept\AtpTestnet\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SocialDept\AtpTestnet\Data\PdsSpec;
use SocialDept\AtpTestnet\Testnet;

/**
 * Integration tests for disposable, consumer-owned PDS support.
 *
 * Requires Docker and Docker Compose, and reaches the PLC over
 * host.docker.internal, which Docker Desktop provides but Linux hosts do not.
 * Grouped as integration so CI can skip it.
 */
#[Group('integration')]
class SpawnPdsTest extends TestCase
{
    private static ?Testnet $testnet = null;

    public static function setUpBeforeClass(): void
    {
        self::$testnet = Testnet::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$testnet?->stop();
        self::$testnet = null;
    }

    public function test_plc_url_for_containers_uses_host_gateway(): void
    {
        $this->assertSame(
            'http://host.docker.internal:'.self::$testnet->config()->plcPort,
            self::$testnet->plcUrlForContainers(),
        );
    }

    public function test_network_name_matches_compose_project(): void
    {
        $this->assertSame(
            self::$testnet->config()->projectName.'_default',
            self::$testnet->networkName(),
        );
    }

    public function test_builtin_pds_exposes_handle_helper(): void
    {
        $this->assertSame('bob.test', self::$testnet->pds()->handle('bob'));
    }

    public function test_spawned_pds_registers_dids_in_shared_plc(): void
    {
        $port = $this->freePort();
        $name = 'atp-testnet-spawn-'.substr(uniqid(), -6);

        $spawned = self::$testnet->spawnPds(new PdsSpec(name: $name, hostPort: $port));

        try {
            $this->assertSame($name, $spawned->containerName);
            $this->assertSame("http://localhost:{$port}", $spawned->url);

            $pds = $spawned->pds();
            $account = $pds->createAccount($pds->handle('alice'));

            $this->assertStringStartsWith('did:plc:', $account->did);

            // The DID created on the spawned PDS resolves on the shared testnet PLC.
            $doc = self::$testnet->plc()->getDocument($account->did);
            $this->assertSame($account->did, $doc['id']);
        } finally {
            self::$testnet->despawnPds($spawned);
        }

        // Container is gone after despawn.
        $inspect = new \Symfony\Component\Process\Process(['docker', 'inspect', $name]);
        $inspect->run();
        $this->assertFalse($inspect->isSuccessful());
    }

    private function freePort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) explode(':', stream_socket_get_name($sock, false))[1];
        fclose($sock);

        return $port;
    }
}

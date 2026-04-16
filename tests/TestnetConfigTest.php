<?php

declare(strict_types=1);

namespace SocialDept\AtpTestnet\Tests;

use PHPUnit\Framework\TestCase;
use SocialDept\AtpTestnet\TestnetConfig;

class TestnetConfigTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Compose override resolution
    // -------------------------------------------------------------------------

    public function test_resolve_returns_null_when_no_override_exists(): void
    {
        $config = new TestnetConfig;

        // Run from a temp dir with no override file
        $original = getcwd();
        chdir(sys_get_temp_dir());

        try {
            $this->assertNull($config->resolveComposeOverride());
        } finally {
            chdir($original);
        }
    }

    public function test_resolve_detects_override_in_working_directory(): void
    {
        $dir = sys_get_temp_dir().'/atp-testnet-config-test-'.uniqid();
        mkdir($dir);
        file_put_contents($dir.'/'.TestnetConfig::COMPOSE_OVERRIDE_FILE, "services: {}\n");

        $config = new TestnetConfig;
        $original = getcwd();
        chdir($dir);

        try {
            $resolved = $config->resolveComposeOverride();
            $this->assertNotNull($resolved);
            $this->assertStringEndsWith(TestnetConfig::COMPOSE_OVERRIDE_FILE, $resolved);
        } finally {
            chdir($original);
            unlink($dir.'/'.TestnetConfig::COMPOSE_OVERRIDE_FILE);
            rmdir($dir);
        }
    }

    public function test_resolve_uses_explicit_path_over_convention(): void
    {
        $explicit = tempnam(sys_get_temp_dir(), 'compose-override-');
        file_put_contents($explicit, "services: {}\n");

        $config = new TestnetConfig(composeOverride: $explicit);

        $this->assertSame($explicit, $config->resolveComposeOverride());

        unlink($explicit);
    }

    public function test_resolve_returns_null_for_missing_explicit_path(): void
    {
        $config = new TestnetConfig(composeOverride: '/nonexistent/path/override.yml');

        $this->assertNull($config->resolveComposeOverride());
    }

    public function test_stub_path_points_to_existing_file(): void
    {
        $this->assertFileExists(TestnetConfig::stubPath());
    }

    public function test_compose_override_file_constant_is_correct(): void
    {
        $this->assertSame('docker-compose.testnet.yml', TestnetConfig::COMPOSE_OVERRIDE_FILE);
    }

    // -------------------------------------------------------------------------
    // Default config values
    // -------------------------------------------------------------------------

    public function test_default_ports(): void
    {
        $config = new TestnetConfig;

        $this->assertSame(7100, $config->plcPort);
        $this->assertSame(7101, $config->relayPort);
        $this->assertSame(7102, $config->pdsPort);
    }

    public function test_default_urls(): void
    {
        $config = new TestnetConfig;

        $this->assertSame('http://localhost:7100', $config->plcUrl());
        $this->assertSame('http://localhost:7101', $config->relayUrl());
        $this->assertSame('http://localhost:7102', $config->pdsUrl());
    }

    public function test_custom_ports_reflect_in_urls(): void
    {
        $config = new TestnetConfig(plcPort: 9100, relayPort: 9101, pdsPort: 9102);

        $this->assertSame('http://localhost:9100', $config->plcUrl());
        $this->assertSame('http://localhost:9101', $config->relayUrl());
        $this->assertSame('http://localhost:9102', $config->pdsUrl());
    }

    public function test_to_env_includes_all_required_vars(): void
    {
        $config = new TestnetConfig;
        $env = $config->toEnv();

        $this->assertArrayHasKey('ATP_TESTNET_PLC_PORT', $env);
        $this->assertArrayHasKey('ATP_TESTNET_RELAY_PORT', $env);
        $this->assertArrayHasKey('ATP_TESTNET_PDS_PORT', $env);
        $this->assertArrayHasKey('ATP_TESTNET_ADMIN_PASSWORD', $env);
        $this->assertArrayHasKey('ATP_TESTNET_JWT_SECRET', $env);
        $this->assertArrayHasKey('ATP_TESTNET_PLC_ROTATION_KEY', $env);
    }
}

<?php

declare(strict_types=1);

namespace SocialDept\AtpTestnet;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Builds Docker images from source for services that don't publish official images.
 */
class ImageBuilder
{
    /** @var array<string, array{repo: string, dockerfile: string, tag: string}> */
    private const IMAGES = [
        'plc' => [
            'repo' => 'https://github.com/did-method-plc/did-method-plc',
            'dockerfile' => 'packages/server/Dockerfile',
            'tag' => 'atp-testnet-plc:latest',
        ],
        'relay' => [
            'repo' => 'https://github.com/bluesky-social/indigo',
            'dockerfile' => 'cmd/relay/Dockerfile',
            'tag' => 'atp-testnet-relay:latest',
        ],
    ];

    private string $buildDir;

    public function __construct(?string $buildDir = null)
    {
        $this->buildDir = $buildDir ?? sys_get_temp_dir().'/atp-testnet-builds';
    }

    /**
     * Build all required images that aren't available locally.
     */
    public function buildAll(): void
    {
        foreach (self::IMAGES as $name => $config) {
            if ($this->imageExists($config['tag'])) {
                continue;
            }

            echo "[atp-testnet] Building {$name} image...\n";
            $this->build($name);
        }
    }

    /**
     * Build a specific image.
     */
    public function build(string $name): void
    {
        $config = self::IMAGES[$name] ?? null;

        if (! $config) {
            throw new RuntimeException("Unknown image: {$name}. Available: ".implode(', ', array_keys(self::IMAGES)));
        }

        $repoDir = $this->buildDir.'/'.$name;

        $this->cloneOrUpdate($config['repo'], $repoDir);
        $this->applyPatches($name, $repoDir);
        $this->dockerBuild($repoDir, $config['dockerfile'], $config['tag']);
    }

    /**
     * Check if a Docker image exists locally.
     */
    public function imageExists(string $tag): bool
    {
        $process = new Process(['docker', 'image', 'inspect', $tag]);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * Force rebuild all images.
     */
    public function rebuildAll(): void
    {
        foreach (self::IMAGES as $name => $config) {
            echo "[atp-testnet] Rebuilding {$name} image...\n";
            $this->build($name);
        }
    }

    /**
     * Get the image tag for a service.
     */
    public static function imageTag(string $name): string
    {
        return self::IMAGES[$name]['tag'] ?? throw new RuntimeException("Unknown image: {$name}");
    }

    /**
     * Apply testnet-specific patches to source code before building.
     */
    private function applyPatches(string $name, string $repoDir): void
    {
        if ($name === 'plc') {
            $this->patchPlcForTestnet($repoDir);
        }

        if ($name === 'relay') {
            $this->patchRelayForTestnet($repoDir);
        }
    }

    /**
     * Patch the PLC server to allow disabling rate limits via env var.
     * The PLC has a hard-coded 10 ops/hour limit that blocks local dev testing.
     */
    private function patchPlcForTestnet(string $repoDir): void
    {
        $constraintsFile = $repoDir.'/packages/server/src/db/index.ts';

        if (! file_exists($constraintsFile)) {
            return;
        }

        $content = file_get_contents($constraintsFile);

        if (str_contains($content, 'PLC_DISABLE_RATE_LIMIT')) {
            return;
        }

        $content = $this->patchOrFail(
            $content,
            'enforceOpsRateLimit(ops)',
            '// TESTNET PATCH: Allow disabling rate limits for local testing
      if (!process.env.PLC_DISABLE_RATE_LIMIT) {
        enforceOpsRateLimit(ops)
      }',
            'enforceOpsRateLimit bypass in db/index.ts',
        );

        file_put_contents($constraintsFile, $content);

        echo "[atp-testnet] Patched PLC for testnet (rate limit bypass via PLC_DISABLE_RATE_LIMIT)\n";
    }

    /**
     * Patch the relay to disable SSRF protection and skip TLS verification.
     * This allows the relay to connect to Docker-internal PDS containers.
     */
    private function patchRelayForTestnet(string $repoDir): void
    {
        $hostCheckerFile = $repoDir.'/cmd/relay/relay/host_checker.go';

        if (! file_exists($hostCheckerFile)) {
            return;
        }

        $content = file_get_contents($hostCheckerFile);

        // Replace the SSRF transport with a permissive one when RELAY_DISABLE_SSRF=1
        if (! str_contains($content, 'RELAY_DISABLE_SSRF')) {
            $content = $this->patchOrFail(
                $content,
                'func NewHostClient(userAgent string) *HostClient {',
                'func NewHostClient(userAgent string) *HostClient {
	// TESTNET PATCH: Allow disabling SSRF protection for local testing
	if os.Getenv("RELAY_DISABLE_SSRF") == "1" {
		if userAgent == "" {
			userAgent = "indigo-relay (atproto-relay)"
		}
		c := http.Client{
			Timeout: 5 * time.Second,
			Transport: &http.Transport{
				TLSClientConfig: &crypto_tls.Config{InsecureSkipVerify: true},
			},
		}
		return &HostClient{Client: &c, UserAgent: userAgent}
	}',
                'NewHostClient SSRF bypass in host_checker.go',
            );

            // Add import for crypto/tls and os if not present
            if (! str_contains($content, '"crypto/tls"')) {
                $content = str_replace(
                    '"net/http"',
                    "crypto_tls \"crypto/tls\"\n\t\"net/http\"\n\t\"os\"",
                    $content,
                );
            }

            file_put_contents($hostCheckerFile, $content);
        }

        // Also patch ParseHostname to accept Docker-internal hostnames
        $hostFile = $repoDir.'/cmd/relay/relay/host.go';
        if (file_exists($hostFile)) {
            $hostContent = file_get_contents($hostFile);

            if (! str_contains($hostContent, 'RELAY_DISABLE_SSRF')) {
                // Add os import if not present
                if (! str_contains($hostContent, '"os"')) {
                    $hostContent = str_replace(
                        '"net/url"',
                        "\"net/url\"\n\t\"os\"",
                        $hostContent,
                    );
                }

                // Add bypass at the top of ParseHostname
                $hostContent = $this->patchOrFail(
                    $hostContent,
                    'func ParseHostname(raw string) (hostname string, noSSL bool, err error) {',
                    'func ParseHostname(raw string) (hostname string, noSSL bool, err error) {
	// TESTNET PATCH: Accept any hostname when SSRF is disabled
	if os.Getenv("RELAY_DISABLE_SSRF") == "1" {
		if !strings.Contains(raw, "://") {
			raw = "http://" + raw
		}
		u, err := url.Parse(raw)
		if err != nil {
			return "", false, err
		}
		noSSL = u.Scheme == "http" || u.Scheme == "ws"
		h := u.Hostname()
		if u.Port() != "" {
			h = h + ":" + u.Port()
		}
		return h, noSSL, nil
	}',
                    'ParseHostname bypass in host.go',
                );

                file_put_contents($hostFile, $hostContent);
            }
        }

        // Patch DomainIsBanned to skip colon/localhost checks when SSRF is disabled
        $domainBanFile = $repoDir.'/cmd/relay/relay/domain_ban.go';
        if (file_exists($domainBanFile)) {
            $domainBanContent = file_get_contents($domainBanFile);

            if (! str_contains($domainBanContent, 'RELAY_DISABLE_SSRF')) {
                // Add os import if not present
                if (! str_contains($domainBanContent, '"os"')) {
                    $domainBanContent = str_replace(
                        '"strings"',
                        "\"os\"\n\t\"strings\"",
                        $domainBanContent,
                    );
                }

                // Add bypass at the top of DomainIsBanned
                $domainBanContent = $this->patchOrFail(
                    $domainBanContent,
                    'func (r *Relay) DomainIsBanned(ctx context.Context, hostname string) (bool, error) {',
                    'func (r *Relay) DomainIsBanned(ctx context.Context, hostname string) (bool, error) {
	// TESTNET PATCH: Skip domain ban checks when SSRF is disabled
	if os.Getenv("RELAY_DISABLE_SSRF") == "1" {
		return false, nil
	}',
                    'DomainIsBanned bypass in domain_ban.go',
                );

                file_put_contents($domainBanFile, $domainBanContent);
            }
        }

        // Patch main.go to skip account host check when SSRF is disabled
        $mainFile = $repoDir.'/cmd/relay/main.go';
        if (file_exists($mainFile)) {
            $mainContent = file_get_contents($mainFile);

            if (! str_contains($mainContent, 'SkipAccountHostCheck')) {
                $mainContent = $this->patchOrFail(
                    $mainContent,
                    'relayConfig.LenientSyncValidation = cmd.Bool("lenient-sync-validation")',
                    'relayConfig.LenientSyncValidation = cmd.Bool("lenient-sync-validation")
	// TESTNET PATCH: Skip account host check when SSRF is disabled
	if os.Getenv("RELAY_DISABLE_SSRF") == "1" {
		relayConfig.SkipAccountHostCheck = true
	}',
                    'SkipAccountHostCheck in main.go',
                );

                // Add os import if not present
                if (! str_contains($mainContent, '"os"')) {
                    $mainContent = str_replace(
                        '"net/http"',
                        "\"net/http\"\n\t\"os\"",
                        $mainContent,
                    );
                }

                file_put_contents($mainFile, $mainContent);
            }
        }

        echo "[atp-testnet] Patched relay for testnet (SSRF + hostname + domain ban + host check bypass)\n";
    }

    /**
     * Apply a patch via str_replace, throwing if the search string wasn't found.
     */
    private function patchOrFail(string $content, string $search, string $replace, string $description): string
    {
        $result = str_replace($search, $replace, $content);

        if ($result === $content) {
            throw new RuntimeException(
                "Relay patch failed: {$description}. "
                .'The upstream source may have changed. '
                .'Delete the build cache and rebuild, or update the patch in ImageBuilder.'
            );
        }

        return $result;
    }

    private function cloneOrUpdate(string $repo, string $dir): void
    {
        if (is_dir($dir.'/.git')) {
            $process = new Process(['git', 'pull', '--ff-only'], $dir);
            $process->setTimeout(120);
            $process->run();

            if ($process->isSuccessful()) {
                return;
            }

            // Shallow clone can't fast-forward — delete and re-clone
            (new Process(['rm', '-rf', $dir]))->run();
        }

        @mkdir($this->buildDir, 0755, true);

        $process = new Process(['git', 'clone', '--depth', '1', $repo, $dir]);
        $process->setTimeout(120);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException("Failed to clone {$repo}: {$process->getErrorOutput()}");
        }
    }

    private function dockerBuild(string $context, string $dockerfile, string $tag): void
    {
        $process = new Process([
            'docker', 'build',
            '-f', $context.'/'.$dockerfile,
            '-t', $tag,
            $context,
        ]);

        $process->setTimeout(600);
        $process->run(function ($type, $buffer) {
            // Stream build output
            echo $buffer;
        });

        if (! $process->isSuccessful()) {
            throw new RuntimeException("Failed to build {$tag}: {$process->getErrorOutput()}");
        }
    }
}

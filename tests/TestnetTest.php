<?php

declare(strict_types=1);

namespace SocialDept\AtpTestnet\Tests;

use PHPUnit\Framework\TestCase;
use SocialDept\AtpTestnet\Testnet;

/**
 * Integration tests that boot a real AT Protocol testnet.
 *
 * Requires Docker and Docker Compose. First run builds images from source.
 */
class TestnetTest extends TestCase
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

    public function test_plc_is_healthy(): void
    {
        $this->assertTrue(self::$testnet->plc()->isHealthy());
    }

    public function test_pds_is_healthy(): void
    {
        $this->assertTrue(self::$testnet->pds()->isHealthy());
    }

    public function test_pds_describes_server(): void
    {
        $server = self::$testnet->pds()->describeServer();

        $this->assertArrayHasKey('availableUserDomains', $server);
        $this->assertContains('.test', $server['availableUserDomains']);
    }

    public function test_can_create_account(): void
    {
        $account = self::$testnet->createAccount('testuser');

        $this->assertStringStartsWith('did:plc:', $account->did);
        $this->assertSame('testuser.test', $account->handle);
        $this->assertNotEmpty($account->accessJwt);
    }

    public function test_account_did_exists_on_plc(): void
    {
        $account = self::$testnet->createAccount('plccheck');

        $doc = self::$testnet->plc()->getDocument($account->did);

        $this->assertSame($account->did, $doc['id']);
        $this->assertContains("at://{$account->handle}", $doc['alsoKnownAs']);
    }

    public function test_plc_operation_log_exists(): void
    {
        $account = self::$testnet->createAccount('logcheck');

        $log = self::$testnet->plc()->getOperationLog($account->did);

        $this->assertNotEmpty($log);
        $this->assertArrayHasKey('type', $log[0]);
    }

    public function test_can_create_session(): void
    {
        $account = self::$testnet->createAccount('sessiontest');

        $session = self::$testnet->pds()->createSession($account->handle, $account->password);

        $this->assertSame($account->did, $session['did']);
        $this->assertArrayHasKey('accessJwt', $session);
    }

    public function test_can_create_multiple_accounts(): void
    {
        $alice = self::$testnet->createAccount('alice2');
        $bob = self::$testnet->createAccount('bob2');

        $this->assertNotSame($alice->did, $bob->did);
        $this->assertSame('alice2.test', $alice->handle);
        $this->assertSame('bob2.test', $bob->handle);
    }

    public function test_testnet_reports_running(): void
    {
        $this->assertTrue(self::$testnet->isRunning());
    }

    // -------------------------------------------------------------------------
    // Record operations
    // -------------------------------------------------------------------------

    public function test_can_create_and_get_record(): void
    {
        $account = self::$testnet->createAccount('recordtest');

        $result = self::$testnet->pds()->createRecord(
            'app.bsky.feed.post',
            [
                '$type' => 'app.bsky.feed.post',
                'text' => 'Hello from atp-testnet!',
                'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
            ],
            $account->accessJwt,
        );

        $this->assertArrayHasKey('uri', $result);
        $this->assertArrayHasKey('cid', $result);

        // Extract rkey from URI: at://did/collection/rkey
        $parts = explode('/', $result['uri']);
        $rkey = end($parts);

        $record = self::$testnet->pds()->getRecord(
            $account->did,
            'app.bsky.feed.post',
            $rkey,
        );

        $this->assertSame('Hello from atp-testnet!', $record['value']['text']);
    }

    public function test_can_list_records(): void
    {
        $account = self::$testnet->createAccount('listtest');

        for ($i = 0; $i < 3; $i++) {
            self::$testnet->pds()->createRecord(
                'app.bsky.feed.post',
                [
                    '$type' => 'app.bsky.feed.post',
                    'text' => "Post {$i}",
                    'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
                ],
                $account->accessJwt,
            );
        }

        $records = self::$testnet->pds()->listRecords(
            $account->did,
            'app.bsky.feed.post',
        );

        $this->assertCount(3, $records['records']);
    }

    public function test_can_delete_record(): void
    {
        $account = self::$testnet->createAccount('deletetest');

        $result = self::$testnet->pds()->createRecord(
            'app.bsky.feed.post',
            [
                '$type' => 'app.bsky.feed.post',
                'text' => 'To be deleted',
                'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
            ],
            $account->accessJwt,
        );

        $parts = explode('/', $result['uri']);
        $rkey = end($parts);

        self::$testnet->pds()->deleteRecord(
            'app.bsky.feed.post',
            $rkey,
            $account->accessJwt,
        );

        $records = self::$testnet->pds()->listRecords(
            $account->did,
            'app.bsky.feed.post',
        );

        $this->assertCount(0, $records['records']);
    }

    // -------------------------------------------------------------------------
    // Identity
    // -------------------------------------------------------------------------

    public function test_can_resolve_handle(): void
    {
        $account = self::$testnet->createAccount('resolvetest');

        $result = self::$testnet->pds()->resolveHandle($account->handle);

        $this->assertSame($account->did, $result['did']);
    }

    public function test_can_describe_repo(): void
    {
        $account = self::$testnet->createAccount('repotest');

        $repo = self::$testnet->pds()->describeRepo($account->did);

        $this->assertSame($account->did, $repo['did']);
        $this->assertSame($account->handle, $repo['handle']);
    }

    // -------------------------------------------------------------------------
    // Blob upload
    // -------------------------------------------------------------------------

    public function test_can_upload_blob(): void
    {
        $account = self::$testnet->createAccount('blobtest');

        // 1x1 red PNG
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==');

        $result = self::$testnet->pds()->uploadBlob($png, 'image/png', $account->accessJwt);

        $this->assertArrayHasKey('blob', $result);
    }

    // -------------------------------------------------------------------------
    // PLC
    // -------------------------------------------------------------------------

    public function test_plc_audit_log(): void
    {
        $account = self::$testnet->createAccount('audittest');

        $audit = self::$testnet->plc()->getAuditLog($account->did);

        $this->assertNotEmpty($audit);
    }

    public function test_plc_document_data(): void
    {
        $account = self::$testnet->createAccount('datatest');

        $data = self::$testnet->plc()->getDocumentData($account->did);

        $this->assertSame($account->did, $data['did']);
        $this->assertArrayHasKey('verificationMethods', $data);
        $this->assertArrayHasKey('rotationKeys', $data);
    }

    // -------------------------------------------------------------------------
    // Session management
    // -------------------------------------------------------------------------

    public function test_can_get_session(): void
    {
        $account = self::$testnet->createAccount('getsession');

        $session = self::$testnet->pds()->getSession($account->accessJwt);

        $this->assertSame($account->did, $session['did']);
        $this->assertSame($account->handle, $session['handle']);
    }

    // -------------------------------------------------------------------------
    // Authenticated client
    // -------------------------------------------------------------------------

    public function test_authenticated_client(): void
    {
        $account = self::$testnet->createAccount('clienttest');

        $client = self::$testnet->authenticatedClient($account);

        $response = $client->get('/xrpc/com.atproto.server.getSession');
        $data = json_decode($response->getBody()->getContents(), true);

        $this->assertSame($account->did, $data['did']);
    }

    // -------------------------------------------------------------------------
    // Admin operations
    // -------------------------------------------------------------------------

    public function test_admin_get_account_info(): void
    {
        $account = self::$testnet->createAccount('admininfo');

        $info = self::$testnet->pds()->getAccountInfo($account->did);

        $this->assertSame($account->did, $info['did']);
        $this->assertSame($account->handle, $info['handle']);
    }

    // -------------------------------------------------------------------------
    // PLC Operations (signed)
    // -------------------------------------------------------------------------

    public function test_can_update_service_endpoint_via_plc(): void
    {
        $account = self::$testnet->createAccount('plcupdate');
        $signer = self::$testnet->rotationKeypair();

        // Update to new endpoint
        self::$testnet->plc()->updateServiceEndpoint(
            $account->did,
            'https://new-pds.example.com',
            $signer,
        );

        // Verify DID doc updated
        $doc = self::$testnet->plc()->getDocument($account->did);
        $this->assertSame('https://new-pds.example.com', $doc['service'][0]['serviceEndpoint']);

        // Operation log should have 2 entries now
        $log = self::$testnet->plc()->getOperationLog($account->did);
        $this->assertCount(2, $log);
    }

    public function test_can_update_handle_via_plc(): void
    {
        $account = self::$testnet->createAccount('handleupdate');
        $signer = self::$testnet->rotationKeypair();

        self::$testnet->plc()->updateHandle(
            $account->did,
            'newhandle.test',
            $signer,
        );

        $doc = self::$testnet->plc()->getDocument($account->did);
        $this->assertContains('at://newhandle.test', $doc['alsoKnownAs']);
    }

    // -------------------------------------------------------------------------
    // Relay
    // -------------------------------------------------------------------------

    public function test_relay_is_healthy(): void
    {
        // Reset PDS + PLC before relay tests to avoid interference from PLC operation tests
        // Don't reset relay — it needs its subscription state intact
        self::$testnet->resetPds();
        self::$testnet->resetPlc();

        $this->assertTrue(self::$testnet->relay()->isHealthy());
    }

    public function test_relay_discovers_repos_after_crawl(): void
    {
        $account = self::$testnet->createAccount('relaydiscover');

        self::$testnet->pds()->createRecord(
            'app.bsky.feed.post',
            [
                '$type' => 'app.bsky.feed.post',
                'text' => 'Relay should see this',
                'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
            ],
            $account->accessJwt,
        );

        self::$testnet->requestRelayCrawl();

        // Relay needs time to crawl — poll until repos appear
        $result = [];
        for ($i = 0; $i < 10; $i++) {
            sleep(2);
            $result = self::$testnet->relay()->listRepos();
            if (! empty($result['repos'])) {
                break;
            }
        }

        $this->assertArrayHasKey('repos', $result);
        $this->assertNotEmpty($result['repos']);

        $dids = array_column($result['repos'], 'did');
        $this->assertContains($account->did, $dids);
    }

    public function test_firehose_receives_events(): void
    {
        self::$testnet->requestRelayCrawl();

        $account = self::$testnet->createAccount('firehosetest');

        self::$testnet->pds()->createRecord(
            'app.bsky.feed.post',
            [
                '$type' => 'app.bsky.feed.post',
                'text' => 'Firehose should see this',
                'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
            ],
            $account->accessJwt,
        );

        sleep(3);

        $frames = self::$testnet->relay()->consumeFirehose(timeoutMs: 3000, cursor: 0);

        $this->assertNotEmpty($frames, 'Firehose should have events');
    }

    public function test_firehose_sees_create_and_delete(): void
    {
        self::$testnet->requestRelayCrawl();

        $account = self::$testnet->createAccount('firehosecrud');

        $result = self::$testnet->pds()->createRecord(
            'app.bsky.feed.post',
            [
                '$type' => 'app.bsky.feed.post',
                'text' => 'Will be deleted',
                'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
            ],
            $account->accessJwt,
        );

        $parts = explode('/', $result['uri']);
        $rkey = end($parts);

        self::$testnet->pds()->deleteRecord(
            'app.bsky.feed.post',
            $rkey,
            $account->accessJwt,
        );

        sleep(3);

        $frames = self::$testnet->relay()->consumeFirehose(timeoutMs: 3000, cursor: 0);

        $this->assertGreaterThanOrEqual(2, count($frames), 'Should see create + delete events');
    }

    // -------------------------------------------------------------------------
    // PLC rate limit bypass
    // -------------------------------------------------------------------------

    public function test_plc_allows_more_than_10_operations_per_hour(): void
    {
        self::$testnet->resetAll();

        $signer = self::$testnet->rotationKeypair();

        // Create an account (1 PLC operation for the DID creation)
        $account = self::$testnet->createAccount('ratelimit');

        // Perform 12 additional PLC operations — exceeds the default 10/hour limit
        for ($i = 0; $i < 12; $i++) {
            self::$testnet->plc()->updateHandle(
                $account->did,
                "ratelimit{$i}.test",
                $signer,
            );
        }

        // If we got here without a 400 "Too many operations", the bypass works
        $doc = self::$testnet->plc()->getDocument($account->did);
        $this->assertContains('at://ratelimit11.test', $doc['alsoKnownAs']);
    }

    // -------------------------------------------------------------------------
    // Reset (run last — destructive operations)
    // -------------------------------------------------------------------------

    public function test_reset_pds_clears_all_accounts(): void
    {
        $account = self::$testnet->createAccount('reset1');
        self::$testnet->createAccount('reset2');

        $info = self::$testnet->pds()->describeRepo($account->did);
        $this->assertSame($account->did, $info['did']);

        self::$testnet->resetPds();

        $this->expectException(\GuzzleHttp\Exception\ClientException::class);
        self::$testnet->pds()->describeRepo($account->did);
    }

    public function test_reset_pds_allows_new_accounts(): void
    {
        self::$testnet->createAccount('beforereset');
        self::$testnet->resetPds();

        $account = self::$testnet->createAccount('beforereset');
        $this->assertStringStartsWith('did:plc:', $account->did);
        $this->assertSame('beforereset.test', $account->handle);
    }

    public function test_reset_pds_keeps_services_healthy(): void
    {
        self::$testnet->createAccount('healthcheck');
        self::$testnet->resetPds();

        $this->assertTrue(self::$testnet->pds()->isHealthy());
        $this->assertTrue(self::$testnet->plc()->isHealthy());
    }

    public function test_reset_pds_allows_same_email_reuse(): void
    {
        self::$testnet->createAccount('emailtest', 'reuse@test.invalid');
        self::$testnet->resetPds();

        $account = self::$testnet->createAccount('emailtest2', 'reuse@test.invalid');
        $this->assertStringStartsWith('did:plc:', $account->did);
    }

    public function test_reset_plc_clears_all_dids(): void
    {
        $account = self::$testnet->createAccount('plcreset');

        $doc = self::$testnet->plc()->getDocument($account->did);
        $this->assertSame($account->did, $doc['id']);

        self::$testnet->resetPlc();

        $this->expectException(\GuzzleHttp\Exception\ClientException::class);
        self::$testnet->plc()->getDocument($account->did);
    }

    public function test_reset_all_clears_pds_and_plc(): void
    {
        $account = self::$testnet->createAccount('resetall');

        self::$testnet->resetAll();

        $this->assertTrue(self::$testnet->pds()->isHealthy());

        try {
            self::$testnet->plc()->getDocument($account->did);
            $this->fail('Expected exception for missing DID');
        } catch (\GuzzleHttp\Exception\ClientException) {
            $this->assertTrue(true);
        }

        $newAccount = self::$testnet->createAccount('afterfull');
        $this->assertStringStartsWith('did:plc:', $newAccount->did);
    }
}

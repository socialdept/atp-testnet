<?php

declare(strict_types=1);

namespace SocialDept\AtpTestnet\Services;

use GuzzleHttp\Client;
use SocialDept\AtpTestnet\Data\TestAccount;

class PdsService
{
    private Client $client;

    public function __construct(
        string $baseUrl,
        private readonly string $adminPassword,
    ) {
        $this->client = new Client([
            'base_uri' => rtrim($baseUrl, '/'),
            'timeout' => 15,
        ]);
    }

    // -------------------------------------------------------------------------
    // Account lifecycle
    // -------------------------------------------------------------------------

    /**
     * Create an account on the PDS.
     */
    public function createAccount(string $handle, ?string $email = null, ?string $password = null): TestAccount
    {
        $email ??= "{$handle}@test.invalid";
        $password ??= bin2hex(random_bytes(16));

        $data = $this->post('com.atproto.server.createAccount', [
            'handle' => $handle,
            'email' => $email,
            'password' => $password,
        ]);

        return new TestAccount(
            did: $data['did'],
            handle: $data['handle'],
            email: $email,
            password: $password,
            accessJwt: $data['accessJwt'] ?? null,
            refreshJwt: $data['refreshJwt'] ?? null,
        );
    }

    /**
     * Delete an account. Requires account credentials.
     *
     * @return array<string, mixed>
     */
    public function deleteAccount(string $did, string $password, string $token): array
    {
        return $this->post('com.atproto.server.deleteAccount', [
            'did' => $did,
            'password' => $password,
            'token' => $token,
        ]);
    }

    /**
     * Deactivate an account (preserves data).
     */
    public function deactivateAccount(string $accessJwt): array
    {
        return $this->postAuthed('com.atproto.server.deactivateAccount', [], $accessJwt);
    }

    /**
     * Activate a deactivated account.
     */
    public function activateAccount(string $accessJwt): array
    {
        return $this->postAuthed('com.atproto.server.activateAccount', [], $accessJwt);
    }

    // -------------------------------------------------------------------------
    // Sessions
    // -------------------------------------------------------------------------

    /**
     * Create a session (login).
     *
     * @return array<string, mixed>
     */
    public function createSession(string $identifier, string $password): array
    {
        return $this->post('com.atproto.server.createSession', [
            'identifier' => $identifier,
            'password' => $password,
        ]);
    }

    /**
     * Get the current session.
     *
     * @return array<string, mixed>
     */
    public function getSession(string $accessJwt): array
    {
        return $this->getAuthed('com.atproto.server.getSession', [], $accessJwt);
    }

    /**
     * Refresh a session.
     *
     * @return array<string, mixed>
     */
    public function refreshSession(string $refreshJwt): array
    {
        return $this->postAuthed('com.atproto.server.refreshSession', [], $refreshJwt);
    }

    /**
     * Delete (logout) a session.
     */
    public function deleteSession(string $refreshJwt): void
    {
        $this->postAuthed('com.atproto.server.deleteSession', [], $refreshJwt);
    }

    // -------------------------------------------------------------------------
    // Repository operations
    // -------------------------------------------------------------------------

    /**
     * Create a record.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>  Contains uri and cid
     */
    public function createRecord(string $collection, array $record, string $accessJwt, ?string $repo = null): array
    {
        $session = $this->getSession($accessJwt);

        return $this->postAuthed('com.atproto.repo.createRecord', [
            'repo' => $repo ?? $session['did'],
            'collection' => $collection,
            'record' => $record,
        ], $accessJwt);
    }

    /**
     * Get a record.
     *
     * @return array<string, mixed>
     */
    public function getRecord(string $repo, string $collection, string $rkey): array
    {
        return $this->get('com.atproto.repo.getRecord', [
            'repo' => $repo,
            'collection' => $collection,
            'rkey' => $rkey,
        ]);
    }

    /**
     * Delete a record.
     *
     * @return array<string, mixed>
     */
    public function deleteRecord(string $collection, string $rkey, string $accessJwt, ?string $repo = null): array
    {
        $session = $this->getSession($accessJwt);

        return $this->postAuthed('com.atproto.repo.deleteRecord', [
            'repo' => $repo ?? $session['did'],
            'collection' => $collection,
            'rkey' => $rkey,
        ], $accessJwt);
    }

    /**
     * List records in a collection.
     *
     * @return array<string, mixed>
     */
    public function listRecords(string $repo, string $collection, int $limit = 50): array
    {
        return $this->get('com.atproto.repo.listRecords', [
            'repo' => $repo,
            'collection' => $collection,
            'limit' => $limit,
        ]);
    }

    /**
     * Describe a repo.
     *
     * @return array<string, mixed>
     */
    public function describeRepo(string $repo): array
    {
        return $this->get('com.atproto.repo.describeRepo', [
            'repo' => $repo,
        ]);
    }

    /**
     * Upload a blob.
     *
     * @return array<string, mixed>  Contains blob ref
     */
    public function uploadBlob(string $data, string $mimeType, string $accessJwt): array
    {
        $response = $this->client->post('/xrpc/com.atproto.repo.uploadBlob', [
            'headers' => [
                'Authorization' => "Bearer {$accessJwt}",
                'Content-Type' => $mimeType,
            ],
            'body' => $data,
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Get a full repo export (CAR file).
     */
    public function getRepo(string $did): string
    {
        $response = $this->client->get('/xrpc/com.atproto.sync.getRepo', [
            'query' => ['did' => $did],
        ]);

        return $response->getBody()->getContents();
    }

    // -------------------------------------------------------------------------
    // Identity
    // -------------------------------------------------------------------------

    /**
     * Resolve a handle to a DID.
     *
     * @return array<string, mixed>
     */
    public function resolveHandle(string $handle): array
    {
        return $this->get('com.atproto.identity.resolveHandle', [
            'handle' => $handle,
        ]);
    }

    /**
     * Update the authenticated user's handle.
     */
    public function updateHandle(string $handle, string $accessJwt): array
    {
        return $this->postAuthed('com.atproto.identity.updateHandle', [
            'handle' => $handle,
        ], $accessJwt);
    }

    // -------------------------------------------------------------------------
    // Invite codes
    // -------------------------------------------------------------------------

    /**
     * Create an invite code (admin).
     *
     * @return array<string, mixed>
     */
    public function createInviteCode(int $useCount = 1, ?string $forAccount = null): array
    {
        $body = ['useCount' => $useCount];
        if ($forAccount) {
            $body['forAccount'] = $forAccount;
        }

        return $this->postAdmin('com.atproto.server.createInviteCode', $body);
    }

    // -------------------------------------------------------------------------
    // Admin operations
    // -------------------------------------------------------------------------

    /**
     * Get account info (admin).
     *
     * @return array<string, mixed>
     */
    public function getAccountInfo(string $did): array
    {
        return $this->getAdmin('com.atproto.admin.getAccountInfo', [
            'did' => $did,
        ]);
    }

    /**
     * Update account handle (admin).
     *
     * @return array<string, mixed>
     */
    public function adminUpdateHandle(string $did, string $handle): array
    {
        return $this->postAdmin('com.atproto.admin.updateAccountHandle', [
            'did' => $did,
            'handle' => $handle,
        ]);
    }

    /**
     * Update account email (admin).
     *
     * @return array<string, mixed>
     */
    public function adminUpdateEmail(string $did, string $email): array
    {
        return $this->postAdmin('com.atproto.admin.updateAccountEmail', [
            'account' => $did,
            'email' => $email,
        ]);
    }

    /**
     * Update subject status (admin). Used for suspensions/takedowns.
     *
     * @return array<string, mixed>
     */
    public function updateSubjectStatus(string $did, string $deactivated = 'false'): array
    {
        return $this->postAdmin('com.atproto.admin.updateSubjectStatus', [
            'subject' => ['$type' => 'com.atproto.admin.defs#repoRef', 'did' => $did],
            'deactivated' => $deactivated === 'true',
        ]);
    }

    // -------------------------------------------------------------------------
    // Server info
    // -------------------------------------------------------------------------

    /**
     * Describe the server.
     *
     * @return array<string, mixed>
     */
    public function describeServer(): array
    {
        return $this->get('com.atproto.server.describeServer');
    }

    /**
     * Health check.
     */
    public function isHealthy(): bool
    {
        try {
            $response = $this->client->get('/xrpc/_health');
            $data = json_decode($response->getBody()->getContents(), true);

            return isset($data['version']);
        } catch (\Throwable) {
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // HTTP helpers
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function get(string $method, array $query = []): array
    {
        $response = $this->client->get("/xrpc/{$method}", [
            'query' => $query ?: null,
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * @return array<string, mixed>
     */
    private function getAuthed(string $method, array $query, string $jwt): array
    {
        $response = $this->client->get("/xrpc/{$method}", [
            'query' => $query ?: null,
            'headers' => ['Authorization' => "Bearer {$jwt}"],
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * @return array<string, mixed>
     */
    private function post(string $method, array $body = []): array
    {
        $response = $this->client->post("/xrpc/{$method}", [
            'json' => $body ?: null,
        ]);

        $contents = $response->getBody()->getContents();

        return $contents ? json_decode($contents, true) : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function postAuthed(string $method, array $body, string $jwt): array
    {
        $response = $this->client->post("/xrpc/{$method}", [
            'json' => $body ?: null,
            'headers' => ['Authorization' => "Bearer {$jwt}"],
        ]);

        $contents = $response->getBody()->getContents();

        return $contents ? json_decode($contents, true) : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function postAdmin(string $method, array $body = []): array
    {
        $response = $this->client->post("/xrpc/{$method}", [
            'json' => $body ?: null,
            'auth' => ['admin', $this->adminPassword],
        ]);

        $contents = $response->getBody()->getContents();

        return $contents ? json_decode($contents, true) : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function getAdmin(string $method, array $query = []): array
    {
        $response = $this->client->get("/xrpc/{$method}", [
            'query' => $query ?: null,
            'auth' => ['admin', $this->adminPassword],
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }
}
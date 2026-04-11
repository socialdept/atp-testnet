<?php

declare(strict_types=1);

namespace SocialDept\AtpTestnet\Services;

use GuzzleHttp\Client;
use RuntimeException;
use SocialDept\AtpCbor\Crypto\PlcOperation;
use SocialDept\AtpCbor\Crypto\Secp256k1Keypair;

class PlcService
{
    private Client $client;

    public function __construct(string $baseUrl)
    {
        $this->client = new Client([
            'base_uri' => rtrim($baseUrl, '/'),
            'timeout' => 10,
        ]);
    }

    /**
     * Get the DID document for a DID.
     *
     * @return array<string, mixed>
     */
    public function getDocument(string $did): array
    {
        $response = $this->client->get("/{$did}");

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Get the last operation for a DID.
     *
     * @return array<string, mixed>
     */
    public function getLastOperation(string $did): array
    {
        $response = $this->client->get("/{$did}/log/last");

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Get the full operation log for a DID.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getOperationLog(string $did): array
    {
        $response = $this->client->get("/{$did}/log");

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Submit a signed PLC operation.
     *
     * @param array<string, mixed> $operation Signed operation
     */
    public function submitOperation(string $did, array $operation): void
    {
        $response = $this->client->post("/{$did}", [
            'json' => $operation,
        ]);

        if ($response->getStatusCode() >= 400) {
            throw new RuntimeException(
                "PLC operation failed: {$response->getBody()->getContents()}"
            );
        }
    }

    /**
     * Get the audit log for a DID.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAuditLog(string $did): array
    {
        $response = $this->client->get("/{$did}/log/audit");

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Get the DID document data (without the @context wrapper).
     *
     * @return array<string, mixed>
     */
    public function getDocumentData(string $did): array
    {
        $response = $this->client->get("/{$did}/data");

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Update the service endpoint for a DID.
     *
     * Fetches the last operation, builds an update operation, signs it,
     * and submits it to the PLC directory.
     */
    public function updateServiceEndpoint(string $did, string $newEndpoint, Secp256k1Keypair $signer): void
    {
        $lastOp = $this->getLastOperation($did);
        $unsigned = PlcOperation::updateServiceEndpoint($lastOp, $newEndpoint);
        $signed = PlcOperation::sign($unsigned, $signer);

        $this->submitOperation($did, $signed);
    }

    /**
     * Update the handle for a DID.
     */
    public function updateHandle(string $did, string $newHandle, Secp256k1Keypair $signer): void
    {
        $lastOp = $this->getLastOperation($did);
        $unsigned = PlcOperation::updateHandle($lastOp, $newHandle);
        $signed = PlcOperation::sign($unsigned, $signer);

        $this->submitOperation($did, $signed);
    }

    /**
     * Update the rotation keys for a DID.
     *
     * @param  string[]  $newRotationKeys  Array of did:key strings
     */
    public function updateRotationKeys(string $did, array $newRotationKeys, Secp256k1Keypair $signer): void
    {
        $lastOp = $this->getLastOperation($did);
        $unsigned = PlcOperation::updateRotationKeys($lastOp, $newRotationKeys);
        $signed = PlcOperation::sign($unsigned, $signer);

        $this->submitOperation($did, $signed);
    }

    /**
     * Health check.
     */
    public function isHealthy(): bool
    {
        try {
            $response = $this->client->get('/_health');

            return $response->getStatusCode() === 200;
        } catch (\Throwable) {
            return false;
        }
    }
}

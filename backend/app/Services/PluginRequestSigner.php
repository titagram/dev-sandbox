<?php

namespace App\Services;

class PluginRequestSigner
{
    private int $maxClockSkew;

    public function __construct(int $maxClockSkew = 300)
    {
        $this->maxClockSkew = $maxClockSkew;
    }

    /**
     * @throws PluginTokenException
     */
    public function verify(string $method, string $pathWithQuery, string $body, string $storedSecretHash, int $timestamp, string $providedContentSha256, string $providedSignature): void
    {
        if (abs(time() - $timestamp) > $this->maxClockSkew) {
            throw new PluginTokenException('device_signature_invalid', 'Device request timestamp is outside the allowed clock skew.');
        }

        $expectedBodyHash = hash('sha256', $body);

        if (! hash_equals($expectedBodyHash, $providedContentSha256)) {
            throw new PluginTokenException('device_signature_invalid', 'Device request body hash does not match.');
        }

        $canonical = "{$method}\n{$pathWithQuery}\n{$timestamp}\n{$expectedBodyHash}";

        $expectedSignature = 'v1='.hash_hmac('sha256', $canonical, $storedSecretHash);

        if (! hash_equals($expectedSignature, $providedSignature)) {
            throw new PluginTokenException('device_signature_invalid', 'Device request signature is invalid.');
        }
    }
}

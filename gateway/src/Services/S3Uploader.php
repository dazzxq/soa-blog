<?php
declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Minimal AWS Signature V4 PUT client for the Garage (S3-compatible) object store
 * on the user's NAS. We hand-roll SigV4 (no AWS SDK) for exactly ONE operation:
 * authenticated PUT of an image to a path-style bucket. Reads are anonymous/public
 * via S3_PUBLIC_BASE (a separate CDN-cached endpoint), so there is NO download/proxy
 * path here — the gateway only signs writes; the secret never leaves the server.
 *
 * Garage specifics baked in:
 *   - path-style URL  https://<endpoint>/<bucket>/<key>
 *   - region literal  "garage"
 *   - SigV4 with a REAL payload hash (we send the sha256, not UNSIGNED-PAYLOAD)
 *   - NO x-amz-checksum-* / x-amz-sdk-* headers (Garage computes checksums only when
 *     required; omitting them avoids the newer-SDK incompatibility entirely)
 */
final class S3Uploader
{
    private Client $http;
    private string $host;

    public function __construct(
        private string $endpoint,    // https://s3.duyet.vn
        private string $publicBase,  // https://public-s3.duyet.vn
        private string $region,      // garage
        private string $bucket,      // soa
        private string $accessKey,
        private string $secret,
    ) {
        $this->host = (string) parse_url($endpoint, PHP_URL_HOST);
        $this->http = new Client([
            'base_uri'        => rtrim($endpoint, '/') . '/',
            'connect_timeout' => 5.0,
            'timeout'         => 30.0,   // residential NAS uplink can be slow
            'http_errors'     => false,
        ]);
    }

    public function isConfigured(): bool
    {
        return $this->endpoint !== '' && $this->accessKey !== ''
            && $this->secret !== '' && $this->bucket !== '' && $this->host !== '';
    }

    /** Permanent public (anonymous, CDN-cached) URL for a stored key. */
    public function publicUrl(string $key): string
    {
        return rtrim($this->publicBase, '/') . '/' . $this->bucket . '/' . $this->encodeKey($key);
    }

    /**
     * Authenticated SigV4 PUT of $body under $key with the given Content-Type.
     * Returns true on a 2xx response, false otherwise (network error or non-2xx).
     */
    public function put(string $key, string $body, string $contentType): bool
    {
        $service     = 's3';
        $amzDate     = gmdate('Ymd\THis\Z');
        $dateStamp   = gmdate('Ymd');
        $payloadHash = hash('sha256', $body);
        $canonicalUri = '/' . $this->bucket . '/' . $this->encodeKey($key);

        // Canonical headers MUST be lowercase, trimmed, sorted by name, each "k:v\n".
        $canonicalHeaders =
            'content-type:' . $contentType . "\n" .
            'host:' . $this->host . "\n" .
            'x-amz-content-sha256:' . $payloadHash . "\n" .
            'x-amz-date:' . $amzDate . "\n";
        $signedHeaders = 'content-type;host;x-amz-content-sha256;x-amz-date';

        // method \n uri \n query \n canonicalHeaders \n signedHeaders \n payloadHash
        $canonicalRequest = "PUT\n{$canonicalUri}\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";

        $scope        = "{$dateStamp}/{$this->region}/{$service}/aws4_request";
        $stringToSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$scope}\n" . hash('sha256', $canonicalRequest);

        $kDate    = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secret, true);
        $kRegion  = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);   // hex

        $authorization = "AWS4-HMAC-SHA256 Credential={$this->accessKey}/{$scope}, "
            . "SignedHeaders={$signedHeaders}, Signature={$signature}";

        try {
            $res = $this->http->request('PUT', ltrim($canonicalUri, '/'), [
                'headers' => [
                    'Host'                 => $this->host,
                    'Content-Type'         => $contentType,
                    'x-amz-content-sha256' => $payloadHash,
                    'x-amz-date'           => $amzDate,
                    'Authorization'        => $authorization,
                ],
                'body'   => $body,
                'expect' => false,   // no 100-continue handshake
            ]);
        } catch (GuzzleException $e) {
            return false;
        }

        $code = $res->getStatusCode();
        return $code >= 200 && $code < 300;
    }

    /** Percent-encode each path segment per RFC 3986, keep '/' separators. */
    private function encodeKey(string $key): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $key)));
    }
}

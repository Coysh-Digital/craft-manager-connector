<?php

/**
 * Manager Connector plugin for Craft CMS 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

declare(strict_types=1);

namespace coyshdigital\managerconnector\services;

use coyshdigital\managerconnector\Plugin;
use coyshdigital\managerprotocol\CanonicalRequest;
use coyshdigital\managerprotocol\CanonicalResponse;
use coyshdigital\managerprotocol\Nonce;
use coyshdigital\managerprotocol\Protocol;
use Craft;
use craft\base\Component;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

/**
 * Sends signed requests to the Manager platform.
 *
 * Every exchange starts here. The plugin exposes nothing inbound, so this class is the entire
 * surface between a customer's website and the platform.
 *
 * Uses the Guzzle client Craft already ships. Adding an HTTP dependency to privileged code running
 * inside somebody's production site would need a much better reason than convenience.
 */
class Client extends Component
{
    /**
     * Pair with a platform.
     *
     * The one unsigned request the connector ever makes, because at this point it has no identity
     * for the platform to verify. It authenticates with a single-use enrolment code instead.
     *
     * The response **is** signed, and is verified here against the platform public key it carries.
     * That check is the connector's first proof it is talking to the right server rather than to
     * whatever intercepted the request, so a failure is fatal to pairing rather than a warning.
     *
     * @param  array{public: string, secret: string}  $keypair
     * @return array<string, mixed>
     */
    public function pair(string $platformUrl, string $enrolmentCode, array $keypair): array
    {
        // Checked before anything is sent. Pairing is the one request that carries a bearer secret —
        // the enrolment code — so it is the request that must not go out in the clear.
        $platformUrl = self::requireSecureUrl($platformUrl);

        $nonce = Nonce::generate();

        $body = json_encode([
            'enrolment_code' => $enrolmentCode,
            'public_key' => $keypair['public'],
            'connector_version' => Plugin::VERSION,
            'site_url' => $this->siteHost(),
            'nonce' => $nonce,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $response = $this->send($platformUrl, '/api/connector/v1/pair', $body, []);

        $decoded = $this->decode($response['body']);

        if ($response['status'] !== 200) {
            throw new RuntimeException(
                'Pairing was refused by the platform. Correlation ID: '
                . ($decoded['correlation_id'] ?? 'unknown')
            );
        }

        $platformPublicKey = $decoded['platform_public_key'] ?? null;
        $signature = $this->signatureFrom($response['headers']);

        if (!is_string($platformPublicKey) || $signature === null) {
            throw new RuntimeException('The platform did not return a signed pairing response.');
        }

        $canonical = new CanonicalResponse(
            siteId: (string) ($decoded['site_id'] ?? ''),
            requestNonce: $nonce,
            status: $response['status'],
            body: $response['body'],
        );

        // Trust-on-first-use, but verified: the response has to be signed by the key it is
        // offering, and bound to the nonce this request chose.
        if (!$canonical->verify($signature, $platformPublicKey)) {
            throw new RuntimeException(
                'The pairing response failed signature verification. Someone may be intercepting this connection.'
            );
        }

        return $decoded;
    }

    /**
     * Send a signed request as this site.
     *
     * @param  array<string, mixed>  $payload
     * @param  bool  $expectSigned  require and verify a platform signature on the response
     * @return array<string, mixed>
     */
    public function post(string $path, array $payload, bool $expectSigned = false): array
    {
        $connection = Plugin::getInstance()->connection;
        $record = $connection->current();

        if ($record === null) {
            throw new RuntimeException('This site is not paired with a Manager platform.');
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = time();
        $nonce = Nonce::generate();

        $canonical = new CanonicalRequest(
            siteId: $record->siteIdentifier,
            connectorVersion: Plugin::VERSION,
            timestamp: $timestamp,
            nonce: $nonce,
            method: 'POST',
            path: $path,
            body: $body,
        );

        $secret = $connection->secretKey();

        try {
            $signature = $canonical->sign($secret);
        } finally {
            // Out of memory as soon as it has been used.
            sodium_memzero($secret);
        }

        $response = $this->send(self::requireSecureUrl($record->platformUrl), $path, $body, [
            Protocol::HEADER_SITE => $record->siteIdentifier,
            Protocol::HEADER_TIMESTAMP => (string) $timestamp,
            Protocol::HEADER_NONCE => $nonce,
            Protocol::HEADER_CONNECTOR_VERSION => Plugin::VERSION,
            Protocol::HEADER_SIGNATURE => Protocol::SIGNATURE_SCHEME . '=' . $signature,
        ]);

        $decoded = $this->decode($response['body']);

        if ($response['status'] >= 400) {
            throw new RuntimeException(sprintf(
                'The platform rejected the request (HTTP %d). Correlation ID: %s',
                $response['status'],
                $decoded['correlation_id'] ?? 'unknown',
            ));
        }

        // Responses carrying instructions or security-sensitive configuration are signed by the
        // platform, and this is where that signature earns its keep. Without checking it, anything
        // sitting between this site and the platform could hand us a job to run or a capability set
        // to adopt.
        //
        // Fails closed: a missing signature on an endpoint that should have one is treated exactly
        // like an invalid one.
        if ($expectSigned) {
            $signature = $this->signatureFrom($response['headers']);

            $canonical = new CanonicalResponse(
                siteId: $record->siteIdentifier,
                requestNonce: $nonce,
                status: $response['status'],
                body: $response['body'],
            );

            if ($signature === null || !$canonical->verify($signature, $record->platformPublicKey)) {
                throw new RuntimeException(
                    'The platform response failed signature verification and was discarded. '
                    . 'Someone may be intercepting this connection.'
                );
            }
        }

        $connection->recordSuccess();

        return $decoded;
    }

    /**
     * Upload a file to the platform as a signed streaming PUT.
     *
     * Two things make this different from {@see self::post()}, and both matter.
     *
     * The signature covers a hash of the file declared in a header rather than a body held in memory.
     * A database backup cannot be read into a string to be signed — on a site large enough to need
     * managing, that is the request that exhausts PHP's memory limit.
     *
     * And the destination is not a parameter. It is `$record->platformUrl`, stored at pairing and
     * changeable only by re-pairing, which is itself an explicit and audited act. There is no argument
     * to this method that could redirect an artifact somewhere else, which is the point: a compromised
     * platform can ask for a backup, but it cannot ask for one to be sent elsewhere.
     *
     * @return array<string, mixed>
     */
    /**
     * Upload an artifact straight to object storage, using a grant the platform issued.
     *
     * The grant carries a path, a query string and headers. **It does not carry a host, and this method
     * has no parameter that could accept one.** The URL is assembled from `backupUploadHost` in
     * `config/manager-connector.php`, on this server, in the operator's own version control.
     *
     * That is stronger than accepting a host and checking it against an allow-list, because there is no
     * comparison to get wrong — no value the platform sent is used even as an input to one. A
     * compromised platform can vary the path within a bucket the operator already approved, and can do
     * nothing else. A parameter named `url`, `host`, `endpoint`, `destination` or `bucket` on this
     * signature would undo it, and a build check fails if one appears.
     *
     * Redirects are refused rather than followed. A 307 from a storage service is a perfectly ordinary
     * thing that would, here, relocate a customer's database to wherever the response said.
     *
     * @param  array<string, string>  $headers  headers the presigned request committed to
     * @return array{status: int}
     */
    public function putToGrant(
        string $filePath,
        string $grantPath,
        string $grantQuery,
        array $headers,
        int $expiresAt,
        int $maxBytes,
    ): array {
        $settings = Plugin::getInstance()->getSettings();

        $configuredHost = trim($settings->backupUploadHost);

        if ($configuredHost === '') {
            throw new RuntimeException('this site has no backup upload host configured');
        }

        // Checked here rather than trusted from the settings model, because this is the one value
        // standing between a grant and an arbitrary destination. Anything carrying a scheme, a slash,
        // a colon or an @ is refused outright rather than escaped.
        if (preg_match('/^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/i', $configuredHost) !== 1) {
            throw new RuntimeException('the configured backup upload host is not a bare hostname');
        }

        if (!str_starts_with($grantPath, '/') || str_contains($grantPath, '..')) {
            throw new RuntimeException('the platform issued an unusable upload path');
        }

        if ($expiresAt <= time()) {
            // Checked before the socket opens. Uploading a gigabyte to a grant that has already lapsed
            // wastes the site's bandwidth and ends in a rejection either way.
            throw new RuntimeException('the upload grant expired before the backup was ready');
        }

        $bytes = filesize($filePath);

        if ($bytes === false || $bytes <= 0) {
            throw new RuntimeException('There is nothing to upload.');
        }

        if ($bytes > $maxBytes) {
            throw new RuntimeException('the artifact is larger than the upload grant permits');
        }

        $handle = fopen($filePath, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Could not open the artifact for upload.');
        }

        try {
            $client = Craft::createGuzzleClient([
                'timeout' => $settings->uploadTimeout,
                'connect_timeout' => $settings->timeout,
                'http_errors' => false,

                // A storage service answering with a redirect must not be able to send a customer's
                // database somewhere else.
                'allow_redirects' => false,

                // TLS verification, always, with no setting to turn it off. A "disable certificate
                // checking" option exists to be switched on during a support call and left on.
                'verify' => true,
            ]);

            $response = $client->put('https://' . $configuredHost . $grantPath . '?' . $grantQuery, [
                'body' => $handle,
                'headers' => array_merge($headers, [
                    'Content-Length' => (string) $bytes,
                    'Content-Type' => 'application/octet-stream',
                ]),
            ]);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            // The storage service's body is not passed through. It can name a bucket, an account or a
            // request id, and none of that belongs in a Craft log on a customer's server.
            throw new RuntimeException("the artifact upload was refused with status {$status}");
        }

        return ['status' => $status];
    }

    public function putFile(string $path, string $filePath, string $contentHash): array
    {
        $connection = Plugin::getInstance()->connection;
        $record = $connection->current();

        if ($record === null) {
            throw new RuntimeException('This site is not paired with a Manager platform.');
        }

        $bytes = filesize($filePath);

        if ($bytes === false || $bytes <= 0) {
            throw new RuntimeException('There is nothing to upload.');
        }

        $timestamp = time();
        $nonce = Nonce::generate();

        $canonical = CanonicalRequest::forStream(
            siteId: $record->siteIdentifier,
            connectorVersion: Plugin::VERSION,
            timestamp: $timestamp,
            nonce: $nonce,
            method: 'PUT',
            path: $path,
            bodyHash: $contentHash,
        );

        $secret = $connection->secretKey();

        try {
            $signature = $canonical->sign($secret);
        } finally {
            sodium_memzero($secret);
        }

        $handle = fopen($filePath, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Could not open the artifact for upload.');
        }

        $settings = Plugin::getInstance()->getSettings();

        try {
            $client = Craft::createGuzzleClient([
                // Longer than an ordinary request, because this one is measured in megabytes rather
                // than milliseconds. Still bounded: an upload that stalls has to end eventually.
                'timeout' => $settings->uploadTimeout,
                'connect_timeout' => $settings->timeout,
                'http_errors' => false,
            ]);

            $response = $client->put(rtrim(self::requireSecureUrl($record->platformUrl), '/') . $path, [
                // Guzzle streams from the handle rather than buffering it.
                'body' => $handle,
                'headers' => [
                    Protocol::HEADER_SITE => $record->siteIdentifier,
                    Protocol::HEADER_TIMESTAMP => (string) $timestamp,
                    Protocol::HEADER_NONCE => $nonce,
                    Protocol::HEADER_CONNECTOR_VERSION => Plugin::VERSION,
                    Protocol::HEADER_SIGNATURE => Protocol::SIGNATURE_SCHEME . '=' . $signature,
                    Protocol::HEADER_CONTENT_SHA256 => $contentHash,

                    // Declared so the platform can refuse an oversized upload before reading it. Sent
                    // explicitly because a streamed body would otherwise go out chunked, with no length
                    // for the platform to check.
                    'Content-Length' => (string) $bytes,
                    'Content-Type' => 'application/octet-stream',
                    'Accept' => 'application/json',
                    'User-Agent' => 'ManagerConnector/' . Plugin::VERSION . ' (Craft)',
                ],
            ]);
        } catch (GuzzleException $e) {
            throw new RuntimeException('Could not upload to the Manager platform: ' . $e->getMessage(), 0, $e);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        $decoded = $this->decode((string) $response->getBody());

        if ($response->getStatusCode() >= 400) {
            throw new RuntimeException(sprintf(
                'The platform rejected the artifact (HTTP %d). Correlation ID: %s',
                $response->getStatusCode(),
                $decoded['correlation_id'] ?? 'unknown',
            ));
        }

        $connection->recordSuccess();

        return $decoded;
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{status: int, body: string, headers: array<string, list<string>>}
     */
    /**
     * Refuse a platform URL that is not HTTPS.
     *
     * The specification is explicit that TLS remains mandatory and that signing does not replace it,
     * and until this existed nothing enforced it. A signature protects a request's integrity, not its
     * confidentiality: over plain HTTP the enrolment code is readable by anything on the path, and so
     * is every inventory report that follows.
     *
     * Deliberately no escape hatch for local development. ddev, and every tunnelling service worth
     * using, provide real certificates — so the only thing a bypass would enable is the mistake.
     *
     * @throws RuntimeException
     */
    public static function requireSecureUrl(string $platformUrl): string
    {
        $scheme = strtolower((string) parse_url(trim($platformUrl), PHP_URL_SCHEME));

        if ($scheme !== 'https') {
            throw new RuntimeException(
                'The Manager platform URL must use HTTPS. A signed request over plain HTTP is still '
                . 'readable in transit, including the enrolment code used to pair.'
            );
        }

        return trim($platformUrl);
    }

    private function send(string $platformUrl, string $path, string $body, array $headers): array
    {
        $settings = Plugin::getInstance()->getSettings();

        $client = Craft::createGuzzleClient([
            'timeout' => $settings->timeout,
            'connect_timeout' => $settings->timeout,

            // A non-2xx response is information, not an exception: the platform's rejections carry
            // a correlation ID that an operator needs.
            'http_errors' => false,
        ]);

        try {
            $response = $client->post(rtrim($platformUrl, '/') . $path, [
                'body' => $body,
                'headers' => $headers + [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'User-Agent' => 'ManagerConnector/' . Plugin::VERSION . ' (Craft)',
                ],
            ]);
        } catch (GuzzleException $e) {
            throw new RuntimeException('Could not reach the Manager platform: ' . $e->getMessage(), 0, $e);
        }

        return [
            'status' => $response->getStatusCode(),
            'body' => (string) $response->getBody(),
            'headers' => $response->getHeaders(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $body): array
    {
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, list<string>>  $headers
     */
    private function signatureFrom(array $headers): ?string
    {
        foreach ($headers as $name => $values) {
            if (strcasecmp($name, Protocol::HEADER_SIGNATURE) !== 0) {
                continue;
            }

            $value = $values[0] ?? '';
            $prefix = Protocol::SIGNATURE_SCHEME . '=';

            return str_starts_with($value, $prefix) ? substr($value, strlen($prefix)) : null;
        }

        return null;
    }

    /**
     * The host this site actually serves from.
     *
     * Sent during pairing so the platform can compare it with the domain the operator expected. A
     * mismatch does not fail; it holds the pairing until a person has looked at both values.
     */
    private function siteHost(): string
    {
        $siteUrl = Craft::$app->getSites()->getPrimarySite()->getBaseUrl();

        return $siteUrl === null ? '' : (string) parse_url($siteUrl, PHP_URL_HOST);
    }
}

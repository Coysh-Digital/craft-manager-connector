<?php

/**
 * Manager Connector plugin for Craft CMS 4.x and 5.x
 *
 * @link      https://managerforcraft.com
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
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\LimitStream;
use GuzzleHttp\Psr7\Utils;
use RuntimeException;
use Throwable;

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
     * The Manager Cloud hostname a person reads a control panel on.
     *
     * Named only so that {@see self::canonicalPlatformUrl()} can recognise it when somebody pastes
     * it into the pairing field. Nothing sends anything here.
     */
    private const CLOUD_CONSOLE_HOST = 'console.managerforcraft.com';

    /**
     * The Manager Cloud hostname connector traffic goes to.
     *
     * A backup is one request carrying an entire encrypted database. This is the address published
     * to carry it; the console's is not, and an intermediary in front of it refuses a body that size
     * before the platform is reached.
     */
    private const CLOUD_CONNECTOR_HOST = 'api.managerforcraft.com';

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
        // the enrolment code - so it is the request that must not go out in the clear.
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
            /*
             | The platform's own explanation, ahead of the correlation identifier.
             |
             | This called attribution() alone, so a refusal that arrived with a reason attached
             | discarded it and printed a correlation identifier instead - the same fault that was
             | fixed on the artifact path, on the one request where the person who needs the answer
             | is standing at a terminal watching it fail. They cannot look up a correlation
             | identifier; they do not have the platform yet.
             |
             | It matters most for a refusal that is asking for something back. A platform that
             | rejects the address this site typed can say which one to use instead, and that
             | sentence is the whole remedy - printing "Correlation ID: unknown" in its place turns a
             | one-line fix into a support question.
             |
             | reasonFrom() is the same fixed, platform-composed text it is everywhere else, and safe
             | to print for the same reason: it describes the platform's own refusal and never
             | anything this site reported.
             */
            throw new RuntimeException(
                'Pairing was refused by the platform.'
                . $this->reasonFrom($decoded)
                . $this->attribution($response['status'], $response['body'], $decoded, $response['headers'])
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
     * `$patient` is for the one request that carries nothing and takes a long time: asking the
     * platform to assemble an artifact it has received in parts. The ordinary ten seconds is right
     * for a heartbeat and wrong for that, because the platform hashes the whole reassembled file
     * before it answers - a pass over a database, not over a payload.
     *
     * Reported live, and the failure was worse than a slow request. The connector gave up at ten
     * seconds, reported the job failed, and the platform settled the artifact as failed and deleted
     * the parts - while its own assembly was still running and about to store them. One upload,
     * finished successfully on one side and thrown away by the other.
     *
     * @param  array<string, mixed>  $payload
     * @param  bool  $expectSigned  require and verify a platform signature on the response
     * @param  bool  $patient  allow the upload timeout rather than the ordinary one
     * @return array<string, mixed>
     */
    public function post(string $path, array $payload, bool $expectSigned = false, bool $patient = false): array
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
        ], $patient);

        $decoded = $this->decode($response['body']);

        if ($response['status'] >= 400) {
            throw new RuntimeException(sprintf(
                'The platform rejected the request (HTTP %d).%s%s',
                $response['status'],
                $this->reasonFrom($decoded),
                $this->attribution($response['status'], $response['body'], $decoded, $response['headers']),
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
     * A database backup cannot be read into a string to be signed - on a site large enough to need
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
     * comparison to get wrong - no value the platform sent is used even as an input to one. A
     * compromised platform can vary the path within a bucket the operator already approved, and can do
     * nothing else. A parameter named `url`, `host`, `endpoint`, `destination` or `bucket` on this
     * signature would undo it, and a build check fails if one appears.
     *
     * Redirects are refused rather than followed. A 307 from a storage service is a perfectly ordinary
     * thing that would, here, relocate a customer's database to wherever the response said.
     *
     * An artifact past a few gigabytes cannot go in one request - object stores cap a single PUT at
     * five - so a grant may instead describe a sequence of parts. Each part is its own presigned
     * request with its own path and query, assembled against the same configured host by the same one
     * line, and carrying a bounded slice of the file. Nothing about that changes what this method is
     * allowed to be told: a part carries a path and headers, never a host, and the store checks the
     * assembled whole against a checksum the platform committed to before the first byte was sent.
     *
     * @param  array<string, string>  $headers  headers the presigned request committed to
     * @param  list<array<string, mixed>>  $parts  presigned parts, in order; empty for a single request
     * @return array{status: int}
     */
    public function putToGrant(
        string $filePath,
        string $grantPath,
        string $grantQuery,
        array $headers,
        int $expiresAt,
        int $maxBytes,
        array $parts = [],
        int $partBytes = 0,
    ): array {
        $settings = Plugin::getInstance()->getSettings();

        $record = Plugin::getInstance()->connection->current();

        if ($record === null) {
            throw new RuntimeException('This site is not paired with a Manager platform.');
        }

        $configuredHost = trim($settings->backupUploadHost);

        /*
         | Where the destination comes from when this site has not named one.
         |
         | Derived from the platform URL an operator typed at pairing, and from nothing else. That is
         | the whole property: no value the platform sent is used here, at pairing or per request, so
         | a platform compromised after pairing can vary the path within a bucket and can do nothing
         | else. The same guarantee the config file gave, without requiring one.
         |
         | It has to be that rather than a host on the pairing response, which is the obvious design
         | and the wrong one. A host the platform chose is a host a *compromised* platform chose, and
         | pinning it at pairing only narrows when the choice is made rather than who makes it.
         |
         | The config file still wins when set. An operator pointing sites at their own bucket has
         | named a destination deliberately, and this must not quietly move it.
         */
        if ($configuredHost === '') {
            $configuredHost = self::uploadHostFor($record->platformUrl);
        }

        if ($configuredHost === '') {
            throw new RuntimeException('this site has no backup upload host configured');
        }

        // Checked here rather than trusted from the settings model, because this is the one value
        // standing between a grant and an arbitrary destination. Anything carrying a scheme, a slash,
        // a colon or an @ is refused outright rather than escaped.
        if (preg_match('/^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/i', $configuredHost) !== 1) {
            throw new RuntimeException('the configured backup upload host is not a bare hostname');
        }

        // The same rule for a part's path as for the whole grant's. A part is still a path within a
        // bucket the operator already approved, and there are now many of them rather than one.
        foreach ([$grantPath, ...array_map(static fn(array $p): string => (string) ($p['path'] ?? ''), $parts)] as $candidate) {
            if (!str_starts_with($candidate, '/') || str_contains($candidate, '..')) {
                throw new RuntimeException('the platform issued an unusable upload path');
            }
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

        // Assembled once, from the operator's own configuration, and reused for every request this
        // method makes. A build check refuses any other variable in this position.
        $base = 'https://' . $configuredHost;

        if ($parts === []) {
            return ['status' => $this->putSlice($client, $base . $grantPath . '?' . $grantQuery, $filePath, 0, $bytes, $headers)];
        }

        if ($partBytes <= 0) {
            throw new RuntimeException('the platform issued parts without a part size');
        }

        /*
         | Every part, in order, one at a time.
         |
         | Sequential rather than parallel on purpose. This runs inside a web request or a queue worker
         | on somebody's production site, and saturating their uplink to finish a backup sooner is not
         | this plugin's call to make.
         |
         | A part that fails is retried on its own rather than restarting the upload. On a twenty
         | gigabyte artifact over a domestic connection, one dropped connection near the end would
         | otherwise cost hours of a customer's bandwidth and get no further next time.
         */
        $status = 0;

        foreach ($parts as $index => $part) {
            $offset = $index * $partBytes;
            $length = (int) min($partBytes, $bytes - $offset);

            if ($length <= 0) {
                throw new RuntimeException('the platform issued more parts than the artifact has bytes');
            }

            $url = $base . (string) ($part['path'] ?? '') . '?' . (string) ($part['query'] ?? '');
            $partHeaders = is_array($part['headers'] ?? null) ? array_map('strval', $part['headers']) : [];

            $attempts = 0;

            while (true) {
                $attempts++;

                try {
                    $status = $this->putSlice($client, $url, $filePath, $offset, $length, $partHeaders);

                    break;
                } catch (Throwable $e) {
                    if ($attempts >= 3) {
                        throw new RuntimeException(sprintf(
                            'part %d of %d could not be uploaded: %s',
                            $index + 1,
                            count($parts),
                            $e->getMessage(),
                        ), 0, $e);
                    }
                }
            }
        }

        return ['status' => $status];
    }

    /**
     * Send one presigned request carrying a bounded slice of a file.
     *
     * The slice is a `LimitStream` over the open handle rather than a string, so a part is never held
     * in memory - which is the whole reason an artifact this large can be uploaded from a Craft
     * request at all.
     *
     * @param  array<string, string>  $headers
     */
    private function putSlice(ClientInterface $client, string $url, string $filePath, int $offset, int $length, array $headers): int
    {
        $handle = fopen($filePath, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Could not open the artifact for upload.');
        }

        try {
            $response = $client->request('PUT', $url, [
                'body' => new LimitStream(Utils::streamFor($handle), $length, $offset),
                'headers' => array_merge($headers, [
                    'Content-Length' => (string) $length,
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

        return $status;
    }

    /**
     * The upload host for a platform, when this site has not named one itself.
     *
     * `uploads.` in front of the host an operator typed at pairing, and that is the entire rule. It
     * is a pure function of one string, deliberately: everything that makes this safe rests on there
     * being no other input, so there is no parameter here that a platform response could reach.
     *
     * A platform that wants direct uploads points that name at its bucket. One that does not simply
     * has no DNS there, its grants are refused at connect time, and {@see BackupRunner} falls back to
     * uploading through the platform - which is what every site did before this existed.
     *
     * Returns an empty string rather than guessing when the URL has no host. The caller refuses on
     * empty; a half-parsed URL must never become half a destination.
     */
    public static function uploadHostFor(string $platformUrl): string
    {
        $host = strtolower(trim((string) parse_url($platformUrl, PHP_URL_HOST)));

        // The same bare-hostname rule the configured value is held to, applied to the derived one so
        // that a malformed stored URL cannot produce something that is not a hostname at all.
        if (preg_match('/^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/i', $host) !== 1) {
            return '';
        }

        return 'uploads.' . $host;
    }

    /**
     * Send a whole artifact to the platform in one request.
     *
     * What every connector did before uploading in parts existed, and what one still does when the
     * platform is too old to offer them. Kept rather than deprecated: there is nothing wrong with it
     * on an artifact small enough to send in one go, and a platform that has not been upgraded must
     * keep receiving backups.
     *
     * @return array<string, mixed>
     */
    public function putFile(string $path, string $filePath, string $contentHash): array
    {
        $bytes = filesize($filePath);

        if ($bytes === false || $bytes <= 0) {
            throw new RuntimeException('There is nothing to upload.');
        }

        $decoded = $this->putArtifactBytes($path, $filePath, 0, $bytes, $contentHash);

        Plugin::getInstance()->connection->recordSuccess();

        return $decoded;
    }

    /**
     * Send an artifact to the platform a bounded piece at a time.
     *
     * The reason is a failure mode rather than an optimisation. A request carrying a whole database
     * is a request long enough for a web server or a PHP process on the platform host to end, and
     * when one does this site is handed an HTML error page - no correlation identifier, nothing in
     * the platform's own log, at the end of however long the upload had been running. A request
     * carrying a few megabytes is not, whatever size the database is.
     *
     * Sequential, one at a time, for the reason {@see self::putToGrant()} gives about the direct
     * path: this runs on somebody's production site, and saturating their uplink to finish sooner is
     * not this plugin's call. Each part is retried on its own rather than restarting the upload,
     * because on a large artifact one dropped connection near the end would otherwise cost hours of a
     * customer's bandwidth and get no further next time.
     *
     * **The destination is not a parameter here either.** `$basePath` is a path on the platform this
     * site paired with, exactly as {@see self::putFile()} takes one, and the host is assembled in
     * {@see self::putArtifactBytes()} from the stored connection record. There is no argument on this
     * signature a platform response could reach, and `bin/verify-invariants.php` checks that there is
     * not.
     *
     * @param  string  $basePath  the artifact's content path; each part appends its own number
     */
    public function putFileInParts(string $basePath, string $filePath, int $partBytes): void
    {
        if ($partBytes <= 0) {
            throw new RuntimeException('the platform offered parts without a part size');
        }

        $bytes = filesize($filePath);

        if ($bytes === false || $bytes <= 0) {
            throw new RuntimeException('There is nothing to upload.');
        }

        $part = 1;
        $total = (int) ceil($bytes / $partBytes);

        while (true) {
            $offset = ($part - 1) * $partBytes;

            if ($offset >= $bytes) {
                break;
            }

            $length = (int) min($partBytes, $bytes - $offset);

            // Hashed from the slice this request will actually carry. The signature covers this hash
            // and the path the part number is in, so a part cannot be replayed at another offset.
            $hash = $this->hashSlice($filePath, $offset, $length);

            $attempts = 0;

            while (true) {
                $attempts++;

                try {
                    $this->putArtifactBytes($basePath . '/' . $part, $filePath, $offset, $length, $hash);

                    break;
                } catch (ArtifactPartOutOfOrder $e) {
                    /*
                     | The platform knows where it is and this site does not.
                     |
                     | Which happens after a part whose response never arrived: it was received, this
                     | site did not learn so, and the retry landed somewhere the platform cannot
                     | accept. Jumping to where it says to continue is the whole reason it answers
                     | with a part number instead of a bare refusal - the alternative is sending a
                     | twenty-gigabyte database again.
                     |
                     | Not counted as an attempt. It is not a failure, and treating it as one would
                     | spend the retry budget on the platform being right.
                    */
                    $part = $e->resumeFromPart;

                    continue 2;
                } catch (Throwable $e) {
                    if ($attempts >= 3) {
                        throw new RuntimeException(sprintf(
                            'part %d of %d could not be uploaded: %s',
                            $part,
                            $total,
                            $e->getMessage(),
                        ), 0, $e);
                    }
                }
            }

            $part++;
        }
    }

    /**
     * One signed request carrying a bounded slice of an artifact to the platform.
     *
     * Shared by the whole-file upload and the part-by-part one, so that both get the same signing,
     * the same client hardening and - the reason this is one method rather than two - the same
     * account of *who* refused. A second copy of the response handling would eventually stop
     * distinguishing a platform rejection from a proxy's error page, which is the distinction that
     * cost four nights.
     *
     * The timestamp and nonce are generated here, per request. Signing every part up front would
     * put the last one outside the platform's timestamp tolerance on any upload of consequence.
     *
     * @return array<string, mixed>
     *
     * @throws ArtifactPartOutOfOrder when the platform is expecting a different part
     */
    private function putArtifactBytes(string $path, string $filePath, int $offset, int $length, string $contentHash): array
    {
        $connection = Plugin::getInstance()->connection;
        $record = $connection->current();

        if ($record === null) {
            throw new RuntimeException('This site is not paired with a Manager platform.');
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
                //
                // Per part rather than per artifact once an upload is in pieces, which is a much
                // more useful thing for it to bound: it now means "this part has stalled" instead of
                // "this database is bigger than fifteen minutes of bandwidth".
                'timeout' => $settings->uploadTimeout,
                'connect_timeout' => $settings->timeout,
                'http_errors' => false,

                // The same two the direct-upload client sets, and they were missing here.
                //
                // They arrived with the direct-upload path, where the reasoning is written out: a
                // storage service answering with a redirect must not be able to send a customer's
                // database somewhere else, and a "disable certificate checking" option exists to be
                // switched on during a support call and left on. Both arguments are about this
                // request too - it carries the same artifact - and neither was applied to it.
                //
                // Guzzle follows redirects by default, so this was not a hardening nicety: a 302 in
                // front of the platform would have re-sent the whole encrypted artifact wherever it
                // pointed, with the site's signature attached.
                'allow_redirects' => false,
                'verify' => true,
            ]);

            $response = $client->put(rtrim(self::requireSecureUrl($record->platformUrl), '/') . $path, [
                // A LimitStream over the open handle rather than a string, so no part of a customer's
                // database is ever held in memory - the same construction the direct-upload path uses
                // and for the same reason. For a whole-file upload the window is the whole file, which
                // is what Guzzle streaming from the handle already did.
                'body' => new LimitStream(Utils::streamFor($handle), $length, $offset),
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
                    'Content-Length' => (string) $length,
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

        // Read once. The stream is consumed, so asking for it again returns nothing - and the raw
        // body is what tells a platform rejection apart from a proxy's error page.
        $raw = (string) $response->getBody();
        $decoded = $this->decode($raw);
        $status = $response->getStatusCode();

        // Not a rejection: the platform is telling this site where it actually is. Raised as its own
        // type so the caller can resume rather than counting it against a retry budget.
        if ($status === 409 && ($decoded['error'] ?? null) === 'part_out_of_order') {
            throw new ArtifactPartOutOfOrder(max(1, (int) ($decoded['resume_from_part'] ?? 1)));
        }

        if ($status >= 400) {
            throw new RuntimeException(sprintf(
                'The platform rejected the artifact (HTTP %d).%s%s',
                $status,
                $this->reasonFrom($decoded),
                $this->attribution($status, $raw, $decoded, $response->getHeaders()),
            ));
        }

        return $decoded;
    }

    /**
     * SHA-256 of one slice of a file, read a chunk at a time.
     *
     * Never the whole slice as a string. A part is bounded, but "bounded" is a number an operator
     * sets on the platform and this runs inside somebody's production site - so it is read the same
     * way it is sent.
     */
    private function hashSlice(string $filePath, int $offset, int $length): string
    {
        $handle = fopen($filePath, 'rb');

        if ($handle === false || fseek($handle, $offset) !== 0) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            throw new RuntimeException('Could not read the artifact for hashing.');
        }

        $hash = hash_init('sha256');
        $remaining = $length;

        try {
            while ($remaining > 0) {
                $chunk = fread($handle, (int) min(Protocol::ARTIFACT_CHUNK_BYTES, $remaining));

                if ($chunk === false || $chunk === '') {
                    break;
                }

                hash_update($hash, $chunk);
                $remaining -= strlen($chunk);
            }
        } finally {
            fclose($handle);
        }

        if ($remaining !== 0) {
            throw new RuntimeException('The artifact was shorter than the part it was sliced into.');
        }

        return hash_final($hash);
    }


    /**
     * Refuse a platform URL that is not HTTPS.
     *
     * The specification is explicit that TLS remains mandatory and that signing does not replace it,
     * and until this existed nothing enforced it. A signature protects a request's integrity, not its
     * confidentiality: over plain HTTP the enrolment code is readable by anything on the path, and so
     * is every inventory report that follows.
     *
     * Deliberately no escape hatch for local development. ddev, and every tunnelling service worth
     * using, provide real certificates - so the only thing a bypass would enable is the mistake.
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

    /**
     * The address a site should actually pair against, given the one somebody typed.
     *
     * Manager Cloud serves its control panel and its connector traffic on different hostnames, and
     * only one of them will carry a backup. The control panel's is the address a person has in their
     * browser when they go looking for an enrolment code, so it is the one they paste — and pasting
     * it produced a site that paired cleanly, reported cleanly, and then failed every backup with a
     * 413 raised by an intermediary, with no correlation identifier and nothing in the platform log.
     *
     * Rewriting it here is a deliberate exception to "the address is whatever the operator typed",
     * and it is worth being precise about why it is a narrow one. The replacement is a constant in
     * this file. **No value from any platform response reaches this decision**, which is the property
     * invariant 8 protects — a compromised platform still cannot name a destination, because nothing
     * it sends is consulted here or anywhere else on the way to a host.
     *
     * Matched on the parsed host rather than the whole string, because the string is arrived at by
     * copying: `…/sites`, a trailing slash and a capital letter are all likelier than the bare origin,
     * and an exact comparison would miss every one of them while looking like it worked.
     *
     * Anything else is returned untouched. A self-hosted installation is not addressed by this at all.
     */
    public static function canonicalPlatformUrl(string $platformUrl): string
    {
        $host = strtolower((string) parse_url(trim($platformUrl), PHP_URL_HOST));

        if ($host !== self::CLOUD_CONSOLE_HOST) {
            return trim($platformUrl);
        }

        // Scheme and host only. What was pasted may carry the path of whatever page it came from,
        // and Client::send() appends its own path to this, so keeping one would build a nonsense URL.
        return 'https://' . self::CLOUD_CONNECTOR_HOST;
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{status: int, body: string, headers: array<string, list<string>>}
     */
    private function send(string $platformUrl, string $path, string $body, array $headers, bool $patient = false): array
    {
        $settings = Plugin::getInstance()->getSettings();

        $client = Craft::createGuzzleClient([
            /*
             | Two different questions, and only one of them is about reaching the platform.
             |
             | `connect_timeout` stays short always: a platform that will not answer the socket must
             | never become a slow website, and that is true of every request this makes.
             |
             | The overall budget is the one that varies. Ten seconds is right for anything the
             | platform composes from a row it already has, and wrong for asking it to assemble an
             | artifact - which is a SHA-256 pass over a whole database before it can reply. Sharing
             | one number meant the connector abandoned a successful upload at ten seconds and
             | reported it as failed.
            */
            'timeout' => $patient ? $settings->uploadTimeout : $settings->timeout,
            'connect_timeout' => $settings->timeout,

            // A non-2xx response is information, not an exception: the platform's rejections carry
            // a correlation ID that an operator needs.
            'http_errors' => false,

            // Every request this connector makes, not only the ones carrying a backup. A redirect
            // followed here would re-send a signed request - inventory, findings, a job result - to
            // wherever it pointed, and the signature would travel with it.
            'allow_redirects' => false,

            // Stated rather than left to Guzzle's default, which is the same value. The default is
            // not the point: Craft::createGuzzleClient merges config/guzzle.php, so an installation
            // that turned verification off globally would turn it off here too, silently.
            'verify' => true,
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
     * Why the platform refused, when it said.
     *
     * The rejection body carries a `reason`, and this used to throw it away - reporting a bare
     * status and a correlation identifier instead. That identifier is not always written down on the
     * platform side, so the one line a person had to go on named a record that did not exist, while
     * the actual explanation had arrived in the same response and been discarded.
     *
     * Safe to print. These are fixed messages the platform composes about its own refusal - a quota,
     * an unrecognised format, a limit, an address this site should have paired against instead —
     * never anything a site reported about its contents.
     *
     * @param  array<string, mixed>  $decoded
     */
    private function reasonFrom(array $decoded): string
    {
        $reason = $decoded['reason'] ?? $decoded['error'] ?? null;

        return is_string($reason) && $reason !== '' ? ' ' . rtrim($reason, '.') . '.' : '';
    }

    /**
     * What to say about *who* refused, which is not always the platform.
     *
     * Every rejection the platform composes carries a correlation identifier, in the body and in
     * the `Manager-Correlation-Id` header, and writes a matching line to its own log. Even an
     * unhandled 500 does, since the platform decorates the responses it never planned for. So an
     * error with no identifier at all and a body that is not JSON did not come from the platform:
     * something in front of it answered first, and nothing about it will be in the platform's log.
     *
     * That distinction cost four nights on a live site. A backup failed nightly with
     *
     *     The platform rejected the artifact (HTTP 413). Correlation ID: unknown
     *
     * and the message sent everybody to the platform - to its size ceiling, its configuration, its
     * log - when nginx in front of it was refusing a 2.1 MB body at `client_max_body_size 2m` and
     * the request had never reached PHP at all. The one clue that would have said so was in the
     * response the whole time: no identifier, and an HTML error page where JSON should have been.
     *
     * `unknown` is kept for the case it actually describes - the platform answered, in JSON, and
     * chose not to include one. Merging the two would trade a message that is unhelpful for a
     * message that is wrong.
     *
     * **Which layer to look at depends on the status, and this used to say "body size" for all of
     * them.** That advice was written from the 413 above and is right for a 413. It is wrong for a
     * 502, which is not a refusal at all: it is the upstream dying or timing out while the body was
     * still arriving, and it happened - a console answered a backup with one, and the message sent
     * everybody back to the size ceiling for a second time. A body limit and a request timeout are
     * different settings in different files, and telling somebody to check the wrong one costs
     * exactly as much as telling them nothing.
     *
     * @param  array<string, mixed>  $decoded
     * @param  array<string, list<string>>  $headers
     */
    private function attribution(int $status, string $body, array $decoded, array $headers): string
    {
        $correlationId = $this->correlationFrom($decoded, $headers);

        if ($correlationId !== 'unknown') {
            return ' Correlation ID: ' . $correlationId;
        }

        // The same test decode() applies, asked about the raw body rather than its result: anything
        // that is not a JSON object is not something this platform composed.
        if (!is_array(json_decode($body, true))) {
            $where = in_array($status, [502, 503, 504], true)

                // Nothing refused this. Something upstream of the web server stopped answering while
                // the body was on its way, which on an upload means a timeout rather than a limit -
                // and it is a timeout no setting on the platform can raise, because php-fpm's
                // request_terminate_timeout ends the process from outside PHP.
                ? 'the web server or PHP process on the platform host stopped answering part-way '
                    . 'through - check the request timeouts there rather than the body size limit, '
                    . 'and upgrade the platform if it is old enough to still want the whole artifact '
                    . 'in one request'

                : 'check the upload body size limit on the platform host';

            return ' No correlation ID was returned and the response was not JSON, so a proxy or web '
                . 'server in front of the platform answered this before it reached the application - '
                . $where . '. Nothing about this will appear in the platform log.';
        }

        return ' Correlation ID: unknown';
    }

    /**
     * The identifier that ties this failure to a line in the platform's log.
     *
     * The body is preferred, because a rejection the platform composed deliberately puts it there.
     * The header is the fallback, and it is the case that matters: an *unhandled* failure on the
     * platform produces a body this site's operator cannot rely on - Laravel's own error shape, with
     * no correlation identifier in it - so the connector reported "Correlation ID: unknown" and left
     * nobody anything to search for. That was the whole of what a site could say about a backup that
     * failed every time.
     *
     * @param  array<string, mixed>  $decoded
     * @param  array<string, list<string>>  $headers
     */
    private function correlationFrom(array $decoded, array $headers): string
    {
        $fromBody = $decoded['correlation_id'] ?? null;

        if (is_string($fromBody) && $fromBody !== '') {
            return $fromBody;
        }

        foreach ($headers as $name => $values) {
            if (strcasecmp($name, Protocol::HEADER_CORRELATION_ID) !== 0) {
                continue;
            }

            $value = $values[0] ?? '';

            if ($value !== '') {
                return $value;
            }
        }

        // Still possible, and still worth saying plainly: a proxy or a gateway between this site and
        // the platform can answer without either.
        return 'unknown';
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

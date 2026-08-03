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
use coyshdigital\managerprotocol\ArtifactEnvelope;
use coyshdigital\managerprotocol\ArtifactStream;
use coyshdigital\managerprotocol\KeyFingerprint;
use coyshdigital\managerprotocol\Protocol;
use coyshdigital\managerprotocol\Sealing;
use Craft;
use craft\base\Component;
use RuntimeException;
use Throwable;

/**
 * Takes a database backup, encrypts it, and uploads it to the platform.
 *
 * Four things about how this is written, each of which is a deliberate refusal of an easier option:
 *
 *  - **The dump is Craft's own.** `Craft::$app->getDb()->backupTo()` uses the connection and the
 *    credentials the site already has. There is no `mysqldump` invocation here, no shell, and nothing
 *    the platform could influence — which is what keeps this inside invariant 8. The platform asked for
 *    a backup; it did not say how to take one.
 *
 *  - **Nothing about the database is ever reported.** No credentials, no host, no DSN, no table names.
 *    That is invariant 3 and it is why the report carries sizes and checksums and nothing else.
 *
 *  - **The key is generated here and sealed to the platform.** It never leaves this machine in a form
 *    this machine can read back. A site compromised in September cannot recover the keys to artifacts
 *    it uploaded in June.
 *
 *  - **The plaintext dump is destroyed whatever happens.** It is the most dangerous file on the server
 *    while it exists, so it exists for as short a time as possible and is removed in a `finally`.
 *
 * The destination is not an argument to anything here. {@see Client::putFile()} sends to the platform
 * URL stored at pairing, so there is no code path by which a job payload could redirect an artifact.
 */
class BackupRunner extends Component
{
    /**
     * @var string Cache key holding the size of the last dump this site took.
     *
     * Only ever a number of bytes, and only this site's own. It exists so the next run can tell
     * whether there is room to work before it writes a copy of the database to a disk somebody is
     * running a production site on. Never reported anywhere — a running byte count is a description
     * of how large the database is, which `backup-progress.v1` refuses on purpose.
     */
    private const LAST_DUMP_BYTES = 'manager-connector.last-dump-bytes';

    /**
     * Take a backup for a job and upload it.
     *
     * @param  list<array<string, mixed>>  $offeredRecipients  from a signature-verified claim response
     * @param  list<string>  $acceptedDeclarations  schemas the platform accepts, most preferred first
     * @return array<string, mixed> the job result
     */
    public function run(
        string $jobId,
        array $offeredRecipients = [],
        string $format = Protocol::BACKUP_FORMAT_V1,
        ?int $platformMaxMegabytes = null,
        array $acceptedDeclarations = [],
        ?int $maxArtifactBytes = null,
    ): array {
        $plugin = Plugin::getInstance();

        if (!$plugin->connection->hasCapability('backups:create')) {
            // The connector's own check, independent of the platform's. The platform will not issue this
            // job without the capability; this refuses to act on one if it somehow arrives anyway.
            throw new RuntimeException('this site has not been granted permission to create backups');
        }

        /*
         | Everything that could refuse this backup is decided here, before a dump exists.
         |
         | The ordering is load-bearing rather than tidy. `takeDump()` writes a complete copy of the
         | site's database to disk, which is the most dangerous file that will ever exist on this
         | server. A refusal that happens afterwards has already created it. So the pinning check, the
         | format decision and the key material all resolve first, and the dump is taken only once
         | there is somewhere safe for it to go.
         */
        $floor = $plugin->connection->backupFormatFloor();

        // The floor wins over whatever the platform said. A site that has committed to encrypting
        // backups to its own recovery keys does not go back because a response asked it to.
        $useV2 = $floor === Protocol::BACKUP_FORMAT_V2 || $format === Protocol::BACKUP_FORMAT_V2;

        if ($plugin->recipientPin->isConfigured()) {
            // Pins present means this site has committed, whatever it was told. This is where the
            // ratchet actually engages for a site being set up for the first time.
            $plugin->connection->raiseBackupFormatFloor();
            $useV2 = true;
        }

        /*
         | Which declaration to send, which is a separate question from which format to produce.
         |
         | v2 and v3 describe the same artifact — same envelope, same signing prefix, same encryption,
         | same chunk size. What differs is only the sizes the two documents permit, so this is not a
         | second ratchet and must not be treated as one: {@see Connection::backupFormatFloor()} is a
         | one-way commitment about *who can read a backup*, and dragging a size question into it
         | would make lifting a ceiling irreversible.
         |
         | So it is decided per run, from what the platform said it accepts. Silence means a platform
         | older than v3, and v2 is the answer that has always worked.
         */
        $declaration = $useV2 && in_array('backup.v3', $acceptedDeclarations, true)
            ? 'backup.v3'
            : 'backup.v2';

        $recipients = [];
        $platformKey = null;

        if ($useV2) {
            $recipients = $plugin->recipientPin->accept($offeredRecipients);
        } else {
            $platformKey = $plugin->connection->backupPublicKey();

            if ($platformKey === null) {
                // Refused rather than uploaded unencrypted. A backup is the one thing that must never
                // travel in the clear, so no key means no backup.
                throw new RuntimeException('the platform has no artifact encryption key configured');
            }
        }

        $this->reportStage($jobId, 'dump');

        $dump = null;
        $encrypted = null;

        try {
            $dump = $this->takeDump($platformMaxMegabytes, $maxArtifactBytes);

            $encrypted = $useV2
                ? $this->encryptToRecipients($dump, $jobId, $recipients, $declaration)
                : $this->encrypt($dump['path'], (string) $platformKey);

            $declared = $plugin->client->post('/api/connector/v1/backups', $useV2
                ? array_filter([
                    'schema_version' => $declaration,
                    'job_id' => $jobId,
                    'manifest_b64' => base64_encode($encrypted['manifest_bytes']),
                    'manifest_sha256' => hash('sha256', $encrypted['manifest_bytes']),
                    'manifest_signature' => $encrypted['manifest_signature'],
                    'artifact_sha256' => $encrypted['artifact_sha256'],

                    /*
                     | Only under v3, because v2 has `additionalProperties: false` and would refuse it.
                     |
                     | A transport checksum, not an integrity one. An artifact past a few gigabytes is
                     | assembled from parts by the object store, and a store can only confirm a
                     | whole-object checksum across an assembly when the algorithm linearises — CRC-32C
                     | does, SHA-256 does not. `artifact_sha256` above is unchanged and is still what
                     | binds these bytes for anybody verifying them offline.
                     */
                    'artifact_crc32c' => $declaration === 'backup.v3' ? $encrypted['artifact_crc32c'] : null,

                    'artifact_bytes' => $encrypted['artifact_bytes'],
                    // Where this site *intends* to send the bytes. The platform decides whether to
                    // offer a grant at all, so this is reported again by which endpoint settles the
                    // artifact rather than being believed from here.
                    'upload_mode' => trim($plugin->getSettings()->backupUploadHost) !== '' ? 'direct' : 'platform',
                ], static fn(mixed $value): bool => $value !== null)
                : [
                    'schema_version' => 'backup.v1',
                    'job_id' => $jobId,
                    'artifact' => [
                        'scheme' => ArtifactStream::SCHEME,
                        'header' => $encrypted['header'],
                        'sealed_key' => $encrypted['sealed_key'],
                        'ciphertext_sha256' => $encrypted['ciphertext_sha256'],
                        'plaintext_sha256' => $encrypted['plaintext_sha256'],
                        'ciphertext_bytes' => $encrypted['ciphertext_bytes'],
                        'plaintext_bytes' => $encrypted['plaintext_bytes'],
                        'chunk_bytes' => Protocol::ARTIFACT_CHUNK_BYTES,
                        'taken_at' => $dump['taken_at'],
                        'engine' => $dump['engine'],
                        'engine_version' => $dump['engine_version'],
                        'compressed' => $dump['compressed'],
                    ],
                ]);

            $artifactId = (string) ($declared['artifact'] ?? '');

            if ($artifactId === '') {
                throw new RuntimeException('the platform did not issue an artifact identifier');
            }

            /*
             | Where the bytes go.
             |
             | Two paths, and the choice is made here rather than by the platform. A grant is only
             | honoured when this site has a `backupUploadHost` configured, so an operator who has not
             | opted in gets the ordinary path whatever the platform sends. Anything wrong with a grant
             | — expired, malformed path, oversized — falls back rather than failing: the artifact is
             | already encrypted and the platform can still take it, and losing a backup over a
             | presigned URL that lapsed would be trading the thing that matters for the optimisation.
             */
            $grant = is_array($declared['upload'] ?? null) ? $declared['upload'] : null;
            $uploadedDirect = false;

            if ($grant !== null && trim($plugin->getSettings()->backupUploadHost) !== '') {
                try {
                    $plugin->client->putToGrant(
                        $encrypted['path'],
                        (string) ($grant['path'] ?? ''),
                        (string) ($grant['query'] ?? ''),
                        is_array($grant['headers'] ?? null) ? array_map('strval', $grant['headers']) : [],
                        (int) ($grant['expires_at'] ?? 0),
                        (int) ($grant['max_bytes'] ?? 0),

                        // Present only when the artifact is too large for one request. The platform
                        // decides where the boundaries fall, because it is the side that has to
                        // reassemble them; this site only slices the file where it is told.
                        is_array($grant['parts'] ?? null) ? array_values(array_filter($grant['parts'], 'is_array')) : [],
                        (int) ($grant['part_bytes'] ?? 0),
                    );

                    $uploadedDirect = true;
                } catch (Throwable $e) {
                    Craft::warning(
                        'Manager Connector could not use the direct upload grant and will send the '
                        . 'artifact through the platform instead: ' . $e->getMessage(),
                        'manager-connector',
                    );
                }
            }

            $result = $uploadedDirect
                // The platform did not see these bytes, so it goes and asks storage rather than taking
                // this site's word for it. Nothing is reported here but the fact of having finished.
                ? $plugin->client->post("/api/connector/v1/backups/{$artifactId}/uploaded", [])
                : $plugin->client->putFile(
                    "/api/connector/v1/backups/{$artifactId}/content",
                    $encrypted['path'],
                    $useV2 ? $encrypted['artifact_sha256'] : $encrypted['ciphertext_sha256'],
                );

            Craft::info(
                "Manager Connector uploaded a backup artifact ({$encrypted['ciphertext_bytes']} bytes).",
                'manager-connector',
            );

            if ($useV2) {
                // Only after a v2 artifact has actually completed. Raising the floor on intent rather
                // than on evidence would strand a site whose first attempt failed for an unrelated
                // reason, with no way back except editing the database.
                $plugin->connection->raiseBackupFormatFloor();
            }

            return [
                'stored' => (bool) ($result['stored'] ?? false),
                'artifact' => $artifactId,
                'plaintext_bytes' => $encrypted['plaintext_bytes'],
            ];
        } finally {
            // Both files, whatever happened. The dump is a complete copy of the site's database and the
            // encrypted artifact is the same thing plus a key somebody else holds; neither has any
            // business outliving this method.
            $this->shred($dump['path'] ?? null);
            $this->shred($encrypted['path'] ?? null);
        }
    }

    /**
     * Encrypt a dump and seal its key to every recovery key this site accepted.
     *
     * The key never leaves this machine in a form this machine can read back, exactly as under v1 — the
     * difference is who can. Under v1 the platform could; here only somebody holding one of the
     * organisation's recovery private keys can, and this connector holds none of them.
     *
     * @param  array{path: string, taken_at: int, engine: string, engine_version: string, compressed: bool}  $dump
     * @param  list<array{fingerprint: string, public_key: string, label: string|null}>  $recipients
     * @param  string  $declaration  which declaration schema this artifact is being declared under
     * @return array{path: string, manifest_bytes: string, manifest_signature: string, artifact_sha256: string, artifact_crc32c: string, artifact_bytes: int, ciphertext_sha256: string, plaintext_sha256: string, ciphertext_bytes: int, plaintext_bytes: int}
     */
    private function encryptToRecipients(array $dump, string $jobId, array $recipients, string $declaration = 'backup.v2'): array
    {
        $plugin = Plugin::getInstance();
        $connection = $plugin->connection->current();

        if ($connection === null) {
            throw new RuntimeException('this site is not paired');
        }

        $streamPath = $dump['path'] . '.stream';
        $target = $dump['path'] . '.artifact';

        $key = ArtifactStream::generateKey();

        $input = fopen($dump['path'], 'rb');
        $stream = fopen($streamPath, 'w+b');

        if ($input === false || $stream === false) {
            sodium_memzero($key);

            throw new RuntimeException('could not open the backup for encryption');
        }

        try {
            $written = ArtifactStream::encrypt($input, $stream, $key);

            $manifest = [
                // Paired with the declaration rather than chosen separately. A v3 declaration carries
                // a v3 manifest and a v2 one carries v2; the platform checks that pairing, and a
                // matrix of the four combinations would be four things to get wrong instead of one.
                'manifest_version' => $declaration === 'backup.v3' ? 'backup-manifest.v3' : 'backup-manifest.v2',

                // Minted here, not by the platform. An artifact has to be able to name itself before
                // any platform has seen it, or an exported file sitting in a customer's storage is
                // anonymous.
                'artifact_id' => $this->mintArtifactId(),

                'site_id' => $connection->siteIdentifier,
                'site_key_fingerprint' => KeyFingerprint::forSiteKey($connection->publicKey),
                'taken_at' => $dump['taken_at'],
                'sequence' => $this->nextSequence(),
                'encryption' => [
                    'scheme' => ArtifactStream::SCHEME,
                    'chunk_bytes' => Protocol::ARTIFACT_CHUNK_BYTES,
                    'stream_header' => $written['header'],
                ],
                'key_wrapping' => [
                    'algorithm' => 'x25519-crypto_box_seal-v1',
                    'recipients' => array_map(
                        static fn(array $recipient): array => array_filter([
                            'fingerprint' => $recipient['fingerprint'],
                            'public_key' => $recipient['public_key'],
                            'wrapped_key' => Sealing::seal($key, $recipient['public_key']),
                            'label' => $recipient['label'],
                        ], static fn(mixed $value): bool => $value !== null),
                        $recipients,
                    ),
                ],
                'integrity' => [
                    'plaintext_sha256' => $written['plaintext_sha256'],
                    'ciphertext_sha256' => $written['ciphertext_sha256'],
                    'plaintext_bytes' => $written['plaintext_bytes'],
                    'ciphertext_bytes' => $written['ciphertext_bytes'],
                ],
                'source' => [
                    'engine' => $dump['engine'],
                    'engine_version' => $dump['engine_version'],
                    'compressed' => $dump['compressed'],
                    'compression' => $dump['compressed'] ? 'gzip' : 'none',
                ],
            ];

            // Serialised once. These exact bytes are signed, embedded in the file and sent in the
            // declaration; re-encoding the decoded document anywhere downstream is how a signature
            // stops verifying a year later over how a slash was rendered.
            $manifestBytes = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            $secret = $plugin->connection->secretKey();

            try {
                $manifestSignature = ArtifactEnvelope::signManifest($manifestBytes, $secret);
            } finally {
                sodium_memzero($secret);
            }
        } finally {
            sodium_memzero($key);

            if (is_resource($input)) {
                fclose($input);
            }
        }

        /*
         | The plaintext dump goes now, not in the caller's `finally`.
         |
         | It is the most dangerous file that will ever exist on this server and the class docblock
         | says it should exist for as short a time as possible — but until this line it survived until
         | the upload finished, which on a large site is hours.
         |
         | It also decides how much disk a backup needs. Assembling the envelope holds the `.stream`
         | and the `.artifact` at once, so with the dump still present the peak was three times the
         | dump; now it is two. That is the difference between a 20 GB database needing 60 GB free and
         | needing 40, on a disk the customer owns.
         |
         | The caller still shreds it in its `finally` for the paths that never reach here, and
         | {@see self::shred()} returns quietly when the file is already gone.
         */
        $this->shred($dump['path']);

        $output = fopen($target, 'wb');

        if ($output === false) {
            fclose($stream);
            $this->shred($streamPath);

            throw new RuntimeException('could not assemble the backup artifact');
        }

        try {
            ArtifactEnvelope::write($output, $manifestBytes, $manifestSignature);

            rewind($stream);
            stream_copy_to_stream($stream, $output);
        } finally {
            fclose($stream);
            fclose($output);

            // The bare stream has served its purpose the moment the envelope is assembled around it,
            // and it is a complete encrypted copy of the database with no manifest to explain it.
            $this->shred($streamPath);
        }

        $checksums = $this->checksum($target);

        return [
            'path' => $target,
            'manifest_bytes' => $manifestBytes,
            'manifest_signature' => $manifestSignature,
            'artifact_sha256' => $checksums['sha256'],
            'artifact_crc32c' => $checksums['crc32c'],
            'artifact_bytes' => (int) filesize($target),
            'ciphertext_sha256' => $written['ciphertext_sha256'],
            'plaintext_sha256' => $written['plaintext_sha256'],
            'ciphertext_bytes' => $written['ciphertext_bytes'],
            'plaintext_bytes' => $written['plaintext_bytes'],
        ];
    }

    /**
     * A Crockford base32 identifier for this artifact.
     *
     * Generated here rather than taken from Craft or the platform, because the format's alphabet
     * excludes I, L, O and U and nothing else on hand produces one.
     */
    private function mintArtifactId(): string
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $id = '';

        for ($i = 0; $i < 26; $i++) {
            $id .= $alphabet[random_int(0, 31)];
        }

        return $id;
    }

    /**
     * A monotonic counter for this site's artifacts.
     *
     * Its purpose is to make a rollback visible: two artifacts claiming the same sequence, or a gap
     * where the platform's list shows none, is something an operator can notice. It is not a security
     * control on its own — a compromised site could report anything — and the documentation says so.
     */
    private function nextSequence(): int
    {
        $cache = Craft::$app->getCache();
        $key = 'manager-connector.backup-sequence';

        $next = ((int) $cache->get($key)) + 1;

        // No TTL. A cache that has been cleared restarts the count, which is why a gap is a prompt to
        // look rather than a finding on its own.
        $cache->set($key, $next);

        return $next;
    }

    /**
     * Tell the platform which phase this backup has reached.
     *
     * One report, on the longest phase, and best effort. A dump on a large site can run for many
     * minutes, and without this the platform cannot tell "still working" from "this site has stopped".
     * Everything later is derivable from the declaration, so it is not worth a signed request, a nonce
     * and a rate-limit slot each.
     */
    private function reportStage(string $jobId, string $stage): void
    {
        try {
            Plugin::getInstance()->client->post('/api/connector/v1/backups/progress', [
                'schema_version' => 'backup-progress.v1',
                'job_id' => $jobId,
                'stage' => $stage,
                'at' => time(),
            ]);
        } catch (Throwable) {
            // Never fatal. Failing a backup because a progress report did not send would be trading
            // the thing that matters for the thing that describes it.
        }
    }

    /**
     * Ask Craft to back up its own database.
     *
     * @return array{path: string, taken_at: int, engine: string, engine_version: string, compressed: bool}
     */
    private function takeDump(?int $platformMaxMegabytes = null, ?int $maxArtifactBytes = null): array
    {
        $takenAt = time();

        $this->assertRoomToWork();

        // Craft's own backup, using the connection the site already has. Not a command this connector
        // composed, and not one the platform had any say in.
        try {
            $path = Craft::$app->getDb()->backup();
        } catch (Throwable $e) {
            // Craft's message can carry a path or a connection detail, so it is not passed through.
            throw new RuntimeException('the database backup did not complete', 0, $e);
        }

        if (!is_string($path) || !is_file($path)) {
            throw new RuntimeException('the database backup produced no file');
        }

        $bytes = filesize($path);

        if ($bytes === false || $bytes === 0) {
            $this->shred($path);

            throw new RuntimeException('the database backup was empty');
        }

        /*
         | Whose limit applies.
         |
         | `maxBackupMegabytes` is a safety valve for this machine: it bounds a dump written to this
         | site's own disk, and self-hosted the operator who set it also owns the disk. That is the
         | default and it stands unless the platform says otherwise.
         |
         | A platform that stores and meters the artifact may override it, and zero means no limit at
         | all. That is Manager Cloud: the config file this setting lives in is on the customer's own
         | server, most sites do not have one, and so a default nobody chose was refusing backups of
         | storage already being paid for.
         |
         | Only ever a size. A build check refuses any url, endpoint or destination read from job
         | parameters, and this changes nothing about where the artifact goes or who can open it.
         */
        $megabytes = $platformMaxMegabytes ?? Plugin::getInstance()->getSettings()->maxBackupMegabytes;
        $limit = $megabytes * 1024 * 1024;

        if ($limit > 0 && $bytes > $limit) {
            $this->shred($path);

            throw new RuntimeException(sprintf(
                'the database is larger than this connector is configured to back up (%s, limit %s)',
                $this->readableBytes($bytes),
                $this->readableBytes($limit),
            ));
        }

        /*
         | And what the platform said it will accept.
         |
         | Checked here rather than discovered from a 422 after the artifact has been encrypted and
         | offered. The encrypted file is very close to the dump in size — the envelope is a couple of
         | kilobytes and the stream adds seventeen bytes per megabyte — so the dump is a good enough
         | proxy to refuse on, and refusing here saves a full encryption pass and a second copy of the
         | database on the customer's disk.
         |
         | This is exactly the failure that produced this whole change: a site dumped and encrypted
         | twenty gigabytes every night and was refused at the declaration, having done all the work
         | and kept none of it. A platform that advertises nothing gets the old behaviour.
         */
        if ($maxArtifactBytes !== null && $maxArtifactBytes > 0 && $bytes > $maxArtifactBytes) {
            $this->shred($path);

            throw new RuntimeException(sprintf(
                'the database is larger than the platform will accept (%s, limit %s)',
                $this->readableBytes($bytes),
                $this->readableBytes($maxArtifactBytes),
            ));
        }

        // Recorded now that it is known, so the next run can decide whether there is room before it
        // writes anything. No TTL, for the same reason the sequence counter has none.
        Craft::$app->getCache()->set(self::LAST_DUMP_BYTES, $bytes);

        return [
            'path' => $path,
            'taken_at' => $takenAt,
            'engine' => $this->engine(),
            'engine_version' => $this->engineVersion(),

            // Craft gzips its backups when it can, which shows up in the filename.
            'compressed' => str_ends_with($path, '.gz') || str_ends_with($path, '.zip'),
        ];
    }

    /**
     * Refuse before dumping if there is obviously not room to finish.
     *
     * A backup needs roughly twice the dump on disk: the dump itself, then the encrypted stream and
     * the assembled artifact alongside each other while the envelope is written. Filling a production
     * site's disk is a worse outcome than not taking a backup, and it is one this can see coming.
     *
     * Sized against the last dump this site actually took, cached the way {@see self::nextSequence()}
     * caches its counter, because nothing else here knows how large the database is and asking it
     * would mean a query describing the schema. With no history it cannot know, so it does not guess —
     * a first run, or a cleared cache, passes through and the existing failure path stands.
     *
     * `@disk_free_space()` is the same call {@see SystemReporter} already makes, so there is no new
     * dependency and nothing that shells out.
     */
    private function assertRoomToWork(): void
    {
        $previous = (int) Craft::$app->getCache()->get(self::LAST_DUMP_BYTES);

        if ($previous <= 0) {
            return;
        }

        $path = Craft::$app->getPath()->getDbBackupPath();
        $free = $path === '' ? false : @disk_free_space($path);

        if ($free === false) {
            // Not measurable is not the same as not enough. A container with an unusual mount should
            // not lose its backups to a check that exists to protect it.
            return;
        }

        $needed = (int) ($previous * 2.2);

        if ((int) $free >= $needed) {
            return;
        }

        throw new RuntimeException(sprintf(
            'there is not enough free disk to take a backup safely: about %s is needed and %s is free. '
            . 'A backup holds the dump and the encrypted copy at once.',
            $this->readableBytes($needed),
            $this->readableBytes((int) $free),
        ));
    }

    /**
     * A size somebody can act on, rather than a number they have to divide.
     *
     * These go into failure messages an operator reads on their own site. "the database is larger than
     * this connector is configured to back up" sent people to the wrong place often enough that the
     * numbers are now in the sentence.
     */
    private function readableBytes(int $bytes): string
    {
        if ($bytes >= 1024 ** 3) {
            return round($bytes / 1024 ** 3, 1) . ' GB';
        }

        if ($bytes >= 1024 ** 2) {
            return round($bytes / 1024 ** 2) . ' MB';
        }

        return $bytes . ' bytes';
    }

    /**
     * Encrypt a dump with a fresh key, and seal that key to the platform.
     *
     * @return array{path: string, header: string, sealed_key: string, ciphertext_sha256: string, plaintext_sha256: string, ciphertext_bytes: int, plaintext_bytes: int}
     */
    private function encrypt(string $dumpPath, string $platformKey): array
    {
        $target = $dumpPath . '.artifact';

        $key = ArtifactStream::generateKey();

        $input = fopen($dumpPath, 'rb');
        $output = fopen($target, 'wb');

        if ($input === false || $output === false) {
            sodium_memzero($key);

            throw new RuntimeException('could not open the backup for encryption');
        }

        try {
            $written = ArtifactStream::encrypt($input, $output, $key);

            // Sealed to the platform's public key, which this connector holds but cannot open. Done
            // before the key is zeroed, and it is the only thing that leaves here.
            $sealed = Sealing::seal($key, $platformKey);
        } finally {
            sodium_memzero($key);

            if (is_resource($input)) {
                fclose($input);
            }

            if (is_resource($output)) {
                fclose($output);
            }
        }

        return [
            'path' => $target,
            'header' => $written['header'],
            'sealed_key' => $sealed,
            'ciphertext_sha256' => $written['ciphertext_sha256'],
            'plaintext_sha256' => $written['plaintext_sha256'],
            'ciphertext_bytes' => $written['ciphertext_bytes'],
            'plaintext_bytes' => $written['plaintext_bytes'],
        ];
    }

    /**
     * Which database this is, as a name rather than a connection.
     */
    private function engine(): string
    {
        $db = Craft::$app->getDb();

        if ($db->getIsPgsql()) {
            return 'postgresql';
        }

        // MariaDB reports itself through the same driver as MySQL, and the distinction matters to
        // whoever restores this.
        return str_contains(strtolower($this->engineVersion()), 'mariadb') ? 'mariadb' : 'mysql';
    }

    private function engineVersion(): string
    {
        try {
            return mb_substr((string) Craft::$app->getDb()->getServerVersion(), 0, 32);
        } catch (Throwable) {
            return 'unknown';
        }
    }

    /**
     * Remove a file, best effort.
     *
     * Not a secure erase, and not claimed to be — on a copy-on-write filesystem or an SSD there is no
     * such thing from userland PHP. What this does guarantee is that the file is not left sitting in the
     * site's storage directory waiting for the next person who finds a path traversal.
     */
    /**
     * Both of an artifact's checksums, in one pass over the file.
     *
     * One pass rather than two `hash_file()` calls, because on a twenty-gigabyte artifact the second
     * pass is a second full read of a disk the customer is paying for and waiting on. Nothing is held
     * in memory: the file is read a megabyte at a time and both contexts are fed the same buffer.
     *
     * The pair is not redundancy. SHA-256 is the integrity checksum — signed, covered by the request
     * signature, and what `manager-restore verify` checks offline. CRC-32C exists only because an
     * object store can confirm a whole-object CRC across a multipart assembly and cannot do the same
     * for a SHA, which does not linearise. `crc32c` has been in `hash_algos()` since PHP 7.4, below
     * this plugin's floor.
     *
     * @return array{sha256: string, crc32c: string}
     */
    private function checksum(string $path): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('could not read the backup artifact to check it');
        }

        $sha256 = hash_init('sha256');
        $crc32c = hash_init('crc32c');

        try {
            while (!feof($handle)) {
                $buffer = fread($handle, Protocol::ARTIFACT_CHUNK_BYTES);

                if ($buffer === false) {
                    throw new RuntimeException('could not read the backup artifact to check it');
                }

                hash_update($sha256, $buffer);
                hash_update($crc32c, $buffer);
            }
        } finally {
            fclose($handle);
        }

        return ['sha256' => hash_final($sha256), 'crc32c' => hash_final($crc32c)];
    }

    private function shred(?string $path): void
    {
        if ($path === null || !is_file($path)) {
            return;
        }

        if (!@unlink($path)) {
            // Worth a warning rather than silence: a backup left on disk is the kind of thing an
            // operator should hear about, and the kind of thing a disk-space alert notices later.
            Craft::warning(
                'Manager Connector could not remove a temporary backup file.',
                'manager-connector',
            );
        }
    }
}

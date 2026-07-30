<?php

/**
 * Manager Connector plugin for Craft CMS 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

declare(strict_types=1);

namespace coyshdigital\managerconnector\services;

use Craft;
use coyshdigital\managerconnector\Plugin;
use coyshdigital\managerprotocol\ArtifactStream;
use coyshdigital\managerprotocol\Protocol;
use coyshdigital\managerprotocol\Sealing;
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
     * Take a backup for a job and upload it.
     *
     * @return array<string, mixed> the job result
     */
    public function run(string $jobId): array
    {
        $plugin = Plugin::getInstance();

        if (! $plugin->connection->hasCapability('backups:create')) {
            // The connector's own check, independent of the platform's. The platform will not issue this
            // job without the capability; this refuses to act on one if it somehow arrives anyway.
            throw new RuntimeException('this site has not been granted permission to create backups');
        }

        $platformKey = $plugin->connection->backupPublicKey();

        if ($platformKey === null) {
            // Refused rather than uploaded unencrypted. A backup is the one thing that must never
            // travel in the clear, so no key means no backup.
            throw new RuntimeException('the platform has no artifact encryption key configured');
        }

        $dump = null;
        $encrypted = null;

        try {
            $dump = $this->takeDump();
            $encrypted = $this->encrypt($dump['path'], $platformKey);

            $declared = $plugin->client->post('/api/connector/v1/backups', [
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

            $result = $plugin->client->putFile(
                "/api/connector/v1/backups/{$artifactId}/content",
                $encrypted['path'],
                $encrypted['ciphertext_sha256'],
            );

            Craft::info(
                "Manager Connector uploaded a backup artifact ({$encrypted['ciphertext_bytes']} bytes).",
                'manager-connector',
            );

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
     * Ask Craft to back up its own database.
     *
     * @return array{path: string, taken_at: int, engine: string, engine_version: string, compressed: bool}
     */
    private function takeDump(): array
    {
        $takenAt = time();

        // Craft's own backup, using the connection the site already has. Not a command this connector
        // composed, and not one the platform had any say in.
        try {
            $path = Craft::$app->getDb()->backup();
        } catch (Throwable $e) {
            // Craft's message can carry a path or a connection detail, so it is not passed through.
            throw new RuntimeException('the database backup did not complete', 0, $e);
        }

        if (! is_string($path) || ! is_file($path)) {
            throw new RuntimeException('the database backup produced no file');
        }

        $bytes = filesize($path);

        if ($bytes === false || $bytes === 0) {
            $this->shred($path);

            throw new RuntimeException('the database backup was empty');
        }

        $limit = Plugin::getInstance()->getSettings()->maxBackupMegabytes * 1024 * 1024;

        if ($bytes > $limit) {
            $this->shred($path);

            throw new RuntimeException('the database is larger than this connector is configured to back up');
        }

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
     * Encrypt a dump with a fresh key, and seal that key to the platform.
     *
     * @return array{path: string, header: string, sealed_key: string, ciphertext_sha256: string, plaintext_sha256: string, ciphertext_bytes: int, plaintext_bytes: int}
     */
    private function encrypt(string $dumpPath, string $platformKey): array
    {
        $target = $dumpPath.'.artifact';

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
    private function shred(?string $path): void
    {
        if ($path === null || ! is_file($path)) {
            return;
        }

        if (! @unlink($path)) {
            // Worth a warning rather than silence: a backup left on disk is the kind of thing an
            // operator should hear about, and the kind of thing a disk-space alert notices later.
            Craft::warning(
                'Manager Connector could not remove a temporary backup file.',
                'manager-connector',
            );
        }
    }
}

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
use coyshdigital\managerprotocol\SchemaValidator;
use Craft;
use craft\base\Component;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * How the runtime is configured and how much room it is using.
 *
 * Three sections, and what each deliberately leaves out matters more than what it includes:
 *
 *  - **Storage.** Byte counts and file counts per asset volume, by handle. Never a path, never a
 *    file name, never a listing. "The uploads volume holds 4.2 GB across 18,000 files" is an
 *    operational fact; "here is what is in it" is somebody else's content.
 *  - **PHP.** Numeric limits — memory, execution time, upload size, opcache. Never `phpinfo()`,
 *    never an ini path, never a setting whose value is a filesystem location. The temptation is to
 *    send the lot because it is easy; the reason not to is that half of it names the host.
 *  - **Response times.** Sampled from ordinary traffic by {@see ResponseSampler}, which explains at
 *    length why this is server render time and not time to first byte.
 *
 * Every section is optional in the schema. A volume that cannot be walked, an opcache that is off, a
 * site too quiet to have samples — each simply produces less, and a shorter report is valid rather
 * than deficient.
 */
class SystemReporter extends Component
{
    /**
     * Report versions this connector can build, newest first.
     *
     * Which one is actually sent is the platform's answer, not this plugin's preference — see
     * {@see Connection::systemReportSchema()}. A site reports to a platform somebody else upgrades,
     * so sending the newest by default would refuse every report until they caught up.
     *
     * @var list<string>
     */
    public const SCHEMAS = ['system.v2', 'system.v1'];

    /**
     * What to send until told otherwise. Every platform that has ever existed accepts this.
     */
    public const OLDEST_SCHEMA = 'system.v1';

    /**
     * @return array{payload: array<string, mixed>, problems: list<string>}
     */
    public function buildValidated(?string $schema = null): array
    {
        $schema ??= Plugin::getInstance()->connection->systemReportSchema();

        $payload = $this->build($schema);

        return [
            'payload' => $payload,
            'problems' => SchemaValidator::forSchema($schema)->validate($payload),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function build(?string $schema = null): array
    {
        $schema ??= Plugin::getInstance()->connection->systemReportSchema();

        if (!in_array($schema, self::SCHEMAS, true)) {
            $schema = self::OLDEST_SCHEMA;
        }

        $payload = [
            'schema_version' => $schema,
            'collected_at' => time(),
            'storage' => $this->storage($schema),
            'php' => $this->php(),
        ];

        $response = Plugin::getInstance()->responseSampler->summarise();

        if ($response !== null) {
            $payload['response'] = $response;
        }

        return array_filter($payload, static fn(mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * Sizes of the volumes Craft manages, plus the disk they sit on.
     *
     * @return array<string, mixed>
     */
    private function storage(string $schema): array
    {
        $budget = max(1, Plugin::getInstance()->getSettings()->storageWalkSeconds);
        $deadline = microtime(true) + $budget;

        // Walks already done this run, by resolved path. Craft forbids one volume's subpath from
        // overlapping another's on the same filesystem, so two volumes should never resolve to the
        // same directory — but the budget above is shared by every walk in this method, and a
        // misconfiguration that made them collide would spend it twice and leave the storage
        // directory unmeasured. Cheaper to remember than to re-walk.
        $measured = [];

        $volumes = [];

        foreach ($this->safely(static fn(): array => Craft::$app->getVolumes()->getAllVolumes(), []) as $volume) {
            $handle = (string) $this->safely(static fn(): string => (string) $volume->handle, '');

            if ($handle === '' || preg_match('/^[A-Za-z0-9._-]+$/', $handle) !== 1) {
                continue;
            }

            $volumes[] = $this->measureVolume($volume, $handle, $deadline, $measured, $schema);
        }

        $storagePath = $this->safely(static fn(): string => Craft::$app->getPath()->getStoragePath(), '');

        return array_filter([
            'volumes' => $volumes,
            'storage_bytes' => $storagePath === '' ? null : $this->directorySize($storagePath, $deadline)['bytes'],

            // Free space is the number that turns a backup from a routine job into an outage, and
            // it is the one nobody looks at until it is zero.
            'disk_free_bytes' => $this->safely(static function() use ($storagePath): ?int {
                $free = $storagePath === '' ? false : @disk_free_space($storagePath);

                return $free === false ? null : (int) $free;
            }, null),
            'disk_total_bytes' => $this->safely(static function() use ($storagePath): ?int {
                $total = $storagePath === '' ? false : @disk_total_space($storagePath);

                return $total === false ? null : (int) $total;
            }, null),
        ], static fn(mixed $value): bool => $value !== null);
    }

    /**
     * @param array<string, array{bytes: int, files: int, complete: bool}> $measured
     * @return array<string, mixed>
     */
    private function measureVolume(mixed $volume, string $handle, float $deadline, array &$measured, string $schema): array
    {
        $path = $this->volumePath($volume);

        /*
         | Three ways to end up unmeasured, and until system.v2 they were one.
         |
         | A screen showing "Not measured" against a remote volume, a volume too big for the budget
         | and a volume whose path cannot be opened was showing three situations wanting three
         | different responses — nothing, a larger budget, and somebody fixing a configuration — as
         | one grey badge. Saying which costs a string.
         */
        if ($path === null || !is_dir($path)) {
            $local = $this->isLocal($volume, $path);

            return $this->describeVolume($schema, [
                'handle' => $handle,
                'bytes' => 0,
                'measured' => false,
            ], $local, $local === false ? 'remote' : 'unreadable');
        }

        $result = $measured[$path] ??= $this->directorySize($path, $deadline);

        return $this->describeVolume($schema, [
            'handle' => $handle,
            'bytes' => $result['bytes'],
            'files' => $result['files'],
            'measured' => $result['complete'],
        ], $this->isLocal($volume, $path), $result['complete'] ? null : 'timeout');
    }

    /**
     * Add the fields the chosen schema has room for, and no others.
     *
     * `system.v1` sets `additionalProperties: false`, so a location or a reason sent to a platform
     * that still speaks v1 would not be ignored — the whole report would be refused, silently,
     * because a runtime report is fire-and-forget. Which version is in use is decided by the
     * platform's own reply, and this is where that decision stops being abstract.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function describeVolume(string $schema, array $row, ?bool $local, ?string $reason): array
    {
        if ($schema === self::OLDEST_SCHEMA) {
            return $row;
        }

        if ($local !== null) {
            $row['location'] = $local ? 'local' : 'remote';
        }

        if ($reason !== null) {
            $row['unmeasured_reason'] = $reason;
        }

        return $row;
    }

    /**
     * Whether this volume's files are on the server's own disk.
     *
     * Null rather than a guess when neither signal answers. Both fields are optional in system.v2
     * precisely so that a connector facing an adapter shape it does not recognise can decline to
     * say, and the platform then shows what it showed before — which is better than a confident
     * "remote" against a local volume, because that would read as "these bytes are not on your
     * disk" about bytes that are.
     *
     * Two signals, and they agree in every ordinary case:
     *
     *  - Craft's own local filesystem class. Checked as a string through `is_a`, not imported and
     *    not type-hinted, for the same reason the rest of this file reaches everything through
     *    `method_exists`: this plugin runs inside somebody else's Craft install and a hard
     *    dependency on a class shape is how it starts failing on a version it could have tolerated.
     *  - A resolvable root path. A remote adapter has no directory on this machine to name, so
     *    anything that produced one is local whatever its class is called.
     */
    private function isLocal(mixed $volume, ?string $path): ?bool
    {
        $byClass = $this->safely(static function() use ($volume): ?bool {
            $fs = $volume->getFs();

            return is_a($fs, 'craft\\fs\\Local') ? true : null;
        }, null);

        if ($byClass === true) {
            return true;
        }

        if ($path !== null && $path !== '') {
            return true;
        }

        // No path and not Craft's local class. Every remote adapter lands here; so would a local
        // one whose configuration is empty, which is why the caller reads this as "remote" only
        // for the reason string and not as a claim about a volume it could otherwise measure.
        return $this->safely(static function() use ($volume): ?bool {
            $fs = $volume->getFs();

            // getRootPath is what a filesystem with a directory on this machine implements. Absent
            // means there is nothing local to point at.
            return method_exists($fs, 'getRootPath') ? null : false;
        }, null);
    }

    /**
     * Where one volume's files actually are.
     *
     * A volume is a filesystem *and a subpath within it* — since Craft 4.4, and throughout 5 — and
     * several volumes routinely share one filesystem. Craft has a validator dedicated to stopping
     * their subpaths overlapping, which only makes sense because the arrangement is expected. On
     * Craft 4.0 to 4.3 there is no subpath at all and one volume is one filesystem, which is exactly
     * what the method_exists guard below is for.
     *
     * Reading the filesystem's own root and
     * stopping there is therefore wrong in the common case rather than the exotic one: every volume
     * on a shared filesystem measured the same tree and reported byte-identical figures, and because
     * the platform sums them for its total, a site with three such volumes read three times its real
     * size. It looked plausible enough to survive, which is the worst kind of wrong number.
     *
     * Only volumes backed by the local filesystem can be walked at all. A remote adapter would mean
     * an API call per directory to a third party, billed to the site, to satisfy a dashboard —
     * reported as unmeasured instead, which is the honest answer.
     *
     * Both accessors are reached through method_exists rather than a type hint. The volume arrives
     * here as mixed and stays that way on purpose: this plugin runs inside somebody else's Craft
     * install, and a hard dependency on a class shape is how it would start failing on a version it
     * could otherwise have tolerated.
     */
    private function volumePath(mixed $volume): ?string
    {
        return $this->safely(static function() use ($volume): ?string {
            $fs = $volume->getFs();

            // getRootPath() is Craft's own resolver: it parses the environment variable, normalises
            // the separators and follows symlinks. The fallback does only the first of those, which
            // is what this used to do for every volume.
            $root = method_exists($fs, 'getRootPath')
                ? $fs->getRootPath()
                : (is_string($fs->path ?? null) && $fs->path !== '' ? Craft::parseEnv($fs->path) : null);

            if (!is_string($root) || $root === '') {
                return null;
            }

            $subpath = method_exists($volume, 'getSubpath')
                ? trim((string) $volume->getSubpath(false), '/')
                : '';

            return $subpath === '' ? $root : rtrim($root, '/') . '/' . $subpath;
        }, null);
    }

    /**
     * Walk a tree, stopping at the deadline.
     *
     * Returns what it managed plus whether it finished. A partial figure presented as a total is the
     * failure mode worth designing out: somebody sees a volume shrink by 80% overnight and goes
     * looking for deleted files that were never deleted.
     *
     * @return array{bytes: int, files: int, complete: bool}
     */
    private function directorySize(string $path, float $deadline): array
    {
        $bytes = 0;
        $files = 0;

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY,

                // A permission-denied subdirectory is a fact about the host, not a reason to abandon
                // the measurement and report nothing.
                RecursiveIteratorIterator::CATCH_GET_CHILD,
            );

            foreach ($iterator as $file) {
                // Checked every 512 files rather than every file: microtime() on a million-file tree
                // costs more than the walk it is guarding.
                if (($files & 511) === 0 && microtime(true) > $deadline) {
                    return ['bytes' => $bytes, 'files' => $files, 'complete' => false];
                }

                if ($file->isFile()) {
                    $bytes += (int) $file->getSize();
                    $files++;
                }
            }
        } catch (Throwable) {
            return ['bytes' => $bytes, 'files' => $files, 'complete' => false];
        }

        return ['bytes' => $bytes, 'files' => $files, 'complete' => true];
    }

    /**
     * Numeric limits only.
     *
     * @return array<string, mixed>
     */
    private function php(): array
    {
        $opcache = $this->safely(
            static fn(): array => function_exists('opcache_get_status')
                ? (opcache_get_status(false) ?: [])
                : [],
            [],
        );

        return array_filter([
            'version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'memory_limit_bytes' => $this->bytes(ini_get('memory_limit')),
            'max_execution_time' => (int) ini_get('max_execution_time'),
            'upload_max_filesize_bytes' => max(0, $this->bytes(ini_get('upload_max_filesize'))),
            'post_max_size_bytes' => max(0, $this->bytes(ini_get('post_max_size'))),
            'max_input_vars' => (int) ini_get('max_input_vars'),
            'opcache_enabled' => (bool) ($opcache['opcache_enabled'] ?? false),
            'opcache_memory_used_bytes' => isset($opcache['memory_usage']['used_memory'])
                ? (int) $opcache['memory_usage']['used_memory']
                : null,
            'opcache_memory_free_bytes' => isset($opcache['memory_usage']['free_memory'])
                ? (int) $opcache['memory_usage']['free_memory']
                : null,

            // The count, never the list. Which extensions are loaded fingerprints the host in a way
            // "there are 47 of them" does not.
            'extensions' => count(get_loaded_extensions()),
        ], static fn(mixed $value): bool => $value !== null);
    }

    /**
     * "256M" → 268435456. Preserves -1, which means unlimited and is a real setting.
     */
    private function bytes(string|false $value): int
    {
        $value = trim((string) $value);

        if ($value === '' || $value === '-1') {
            return $value === '-1' ? -1 : 0;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /**
     * @template T
     *
     * @param  callable(): T  $reader
     * @param  T  $fallback
     * @return T
     */
    private function safely(callable $reader, mixed $fallback): mixed
    {
        try {
            return $reader();
        } catch (Throwable $e) {
            Craft::warning(
                'Manager Connector could not read system information: ' . $e->getMessage(),
                'manager-connector',
            );

            return $fallback;
        }
    }
}

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
use coyshdigital\managerprotocol\InventorySections;
use coyshdigital\managerprotocol\SchemaValidator;
use Craft;
use craft\base\Component;
use craft\queue\Queue as CraftQueue;
use Throwable;

/**
 * Builds the operational-metadata report.
 *
 * This is the data-minimisation boundary on the connector side. Read what it gathers and note what
 * it does not: no entries, no assets, no user records, no environment values, no licence keys, no
 * configuration file contents, no log lines, no queue job payloads. Counts and version numbers,
 * and nothing else.
 *
 * The report is validated against the shared schema **before** it is sent. The platform validates
 * again on arrival, but catching it here means a connector bug shows up on the site that has it
 * rather than as a rejection somebody has to go looking for.
 *
 * Every reader is wrapped so that one unavailable Craft API cannot stop a report going out. A
 * missing field is a gap in a dashboard; a connector that throws is a site that looks offline.
 */
class Reporter extends Component
{
    /**
     * Build a report covering only what this site has been granted.
     *
     * Gathering everything and then filtering would mean reading licence state, or the queue, for a
     * site that never asked us to - so each section is only *collected* if the capability is held.
     * The filter afterwards is belt and braces.
     *
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $granted = Plugin::getInstance()->connection->capabilities();

        return InventorySections::filter($this->gather($granted), $granted);
    }

    /**
     * @param  list<string>  $granted
     * @return array<string, mixed>
     */
    private function gather(array $granted): array
    {
        $payload = [
            'schema_version' => 'inventory.v1',
            'collected_at' => time(),
            'connector' => ['version' => Plugin::VERSION],
            'craft' => $this->craft(),
            'php' => ['version' => PHP_VERSION],
            'database' => $this->database(),
            'environment' => $this->environment(),
        ];

        foreach ([
            'plugins' => fn(): array => $this->plugins(),
            'composer_packages' => fn(): array => $this->composerPackages(),
            'config_flags' => fn(): array => $this->configFlags(),
            'queue' => fn(): array => $this->queue(),
            'migrations' => fn(): array => $this->migrations(),
            'licence' => fn(): array => $this->licence(),
        ] as $key => $reader) {
            $capability = InventorySections::capabilityFor($key);

            // Not collected at all without the capability. Reading licence state for a site that
            // never granted licences:read, only to discard it, would still be reading it.
            if ($capability !== null && !in_array($capability, $granted, true)) {
                continue;
            }

            $value = $this->safely($reader, []);

            if ($value !== []) {
                $payload[$key] = $value;
            }
        }

        return $payload;
    }

    /**
     * Build a report and confirm it satisfies the shared schema.
     *
     * @return array{payload: array<string, mixed>, problems: list<string>}
     */
    public function buildValidated(): array
    {
        $payload = $this->build();

        return [
            'payload' => $payload,
            'problems' => SchemaValidator::forSchema('inventory.v1')->validate($payload),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function craft(): array
    {
        $info = Craft::$app->getInfo();

        $craft = [
            'version' => Craft::$app->getVersion(),
            'edition' => $this->editionHandle(),
        ];

        $schemaVersion = $this->safely(static fn(): string => $info->schemaVersion, '');

        if ($schemaVersion !== '') {
            $craft['schema_version'] = $schemaVersion;
        }

        return $craft;
    }

    /**
     * Craft's edition, as one of the handles the schema permits.
     *
     * Read through several APIs because the accessor has moved between Craft 5 point releases, and
     * a connector that breaks on a minor upgrade is worse than one that reports "pro" a version
     * late.
     */
    private function editionHandle(): string
    {
        return $this->safely(static function(): string {
            $edition = Craft::$app->edition;

            if (is_object($edition) && method_exists($edition, 'handle')) {
                return (string) $edition->handle();
            }

            if (is_object($edition) && property_exists($edition, 'name')) {
                return strtolower((string) $edition->name);
            }

            // Craft 5 makes this a backed enum, so it has a value rather than being castable.
            // Older shapes were plain ints, and both reach here from a property with no declared type.
            if ($edition instanceof \BackedEnum) {
                $edition = $edition->value;
            }

            $edition = (int) $edition;

            // The int ladder is not stable across the versions this plugin now supports. Craft added
            // the Team edition in 4.5 and inserted it at 1, moving Pro from 1 to 2 - so on Craft 4.0
            // to 4.4 the same integer means Pro, and reading it with the modern ladder reports a Pro
            // site as "team". Wrong quietly, in a field an operator would have no reason to distrust,
            // which is why it is worth the version check rather than a comment.
            if ($edition === 1 && version_compare(Craft::$app->getVersion(), '4.5', '<')) {
                return 'pro';
            }

            return match ($edition) {
                0 => 'solo',
                1 => 'team',
                default => 'pro',
            };
        }, 'pro');
    }

    /**
     * @return array<string, string>
     */
    private function database(): array
    {
        $db = Craft::$app->getDb();
        $version = $this->safely(static fn(): string => $db->getServerVersion(), '');

        $engine = $db->getIsPgsql() ? 'pgsql' : 'mysql';

        // MariaDB reports itself through the MySQL driver, so the version string is the only way
        // to tell them apart - and they diverge enough that the distinction matters.
        if ($engine === 'mysql' && stripos($version, 'mariadb') !== false) {
            $engine = 'mariadb';
        }

        return [
            'engine' => $engine,

            // Trimmed to the numeric part: the full string carries build and platform detail that
            // nobody needs and that would only widen what is collected.
            'version' => $this->numericVersion($version),
        ];
    }

    /**
     * @return list<array{handle: string, version: string, enabled: bool}>
     */
    private function plugins(): array
    {
        $plugins = [];

        foreach (Craft::$app->getPlugins()->getAllPluginInfo() as $handle => $info) {
            if (!is_array($info)) {
                continue;
            }

            $plugins[] = [
                'handle' => (string) $handle,
                'version' => (string) ($info['version'] ?? 'unknown'),
                'enabled' => (bool) ($info['isEnabled'] ?? false),
            ];
        }

        return array_slice($plugins, 0, 250);
    }

    /**
     * Package names and versions from composer.lock.
     *
     * The lock file itself is never transmitted: it carries source URLs, reference hashes and
     * sometimes private repository addresses. Only the name and version of each package leaves.
     *
     * @return list<array{name: string, version: string}>
     */
    private function composerPackages(): array
    {
        $path = Craft::getAlias('@root') . '/composer.lock';

        if (!is_string($path) || !is_file($path) || !is_readable($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (!is_array($decoded)) {
            return [];
        }

        $packages = [];

        foreach ($decoded['packages'] ?? [] as $package) {
            if (!is_array($package) || !isset($package['name'], $package['version'])) {
                continue;
            }

            $packages[] = [
                'name' => (string) $package['name'],
                'version' => (string) $package['version'],
            ];
        }

        return array_slice($packages, 0, 1000);
    }

    /**
     * Booleans only. Never a configuration value, and never an environment variable.
     *
     * @return array<string, bool>
     */
    private function configFlags(): array
    {
        $general = Craft::$app->getConfig()->getGeneral();

        return [
            'dev_mode' => (bool) $general->devMode,
            'allow_admin_changes' => (bool) $general->allowAdminChanges,
            'allow_updates' => (bool) $general->allowUpdates,
            'headless_mode' => (bool) $general->headlessMode,
            'https_enforced' => $this->httpsEnforced(),
        ];
    }

    private function httpsEnforced(): bool
    {
        $baseUrl = (string) Craft::$app->getSites()->getPrimarySite()->getBaseUrl();

        return str_starts_with(strtolower($baseUrl), 'https://');
    }

    /**
     * Counts only. Job payloads would carry site content.
     *
     * @return array<string, int>
     */
    private function queue(): array
    {
        $queue = Craft::$app->getQueue();

        // Craft's own queue reports these totals; the yii\queue\Queue interface it is typed as does
        // not declare them. A site running a replacement queue driver is reported as zero rather than
        // fataling, which is the same answer safely() would have produced and one query cheaper.
        if (!$queue instanceof CraftQueue) {
            return ['pending' => 0, 'reserved' => 0, 'failed' => 0];
        }

        return [
            'pending' => $this->safely(static fn(): int => $queue->getTotalWaiting(), 0),
            'reserved' => $this->safely(static fn(): int => $queue->getTotalReserved(), 0),
            'failed' => $this->safely(static fn(): int => $queue->getTotalFailed(), 0),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function migrations(): array
    {
        return [
            'pending' => $this->safely(
                static fn(): int => count(Craft::$app->getContentMigrator()->getNewMigrations()),
                0,
            ),
        ];
    }

    /**
     * Licence state, computed here. Licence keys themselves are never transmitted.
     *
     * @return array<string, mixed>
     */
    private function licence(): array
    {
        $plugins = Craft::$app->getPlugins();

        $valid = 0;
        $total = 0;
        $trials = 0;

        foreach ($plugins->getAllPluginInfo() as $handle => $info) {
            if (!is_array($info) || ($info['licenseKey'] ?? null) === null) {
                continue;
            }

            $total++;

            $status = (string) ($info['licenseKeyStatus'] ?? 'unknown');

            if ($status === 'valid') {
                $valid++;
            } elseif ($status === 'trial') {
                $trials++;
            }

            unset($handle);
        }

        return [
            'craft' => $this->craftLicenceState(),
            'plugins_valid' => $valid,
            'plugins_total' => $total,
            'trials_in_use' => $trials,
        ];
    }

    private function craftLicenceState(): string
    {
        return $this->safely(static function(): string {
            $status = (string) Craft::$app->getCache()->get('licenseKeyStatus');

            return match ($status) {
                'valid' => 'valid',
                'trial' => 'trial',
                'invalid' => 'invalid',
                'mismatched' => 'mismatched',
                default => 'unknown',
            };
        }, 'unknown');
    }

    /**
     * A classification, never the value of any environment variable.
     */
    private function environment(): string
    {
        $env = strtolower((string) (Craft::$app->env ?? ''));

        return match (true) {
            in_array($env, ['production', 'prod', 'live'], true) => 'production',
            in_array($env, ['staging', 'stage', 'test'], true) => 'staging',
            in_array($env, ['dev', 'development', 'local'], true) => 'development',
            default => 'unknown',
        };
    }

    private function numericVersion(string $version): string
    {
        preg_match('/\d+(\.\d+)*/', $version, $matches);

        return $matches[0] ?? ($version === '' ? 'unknown' : substr($version, 0, 32));
    }

    /**
     * Run a reader, falling back if Craft cannot answer.
     *
     * A missing field is a gap in a dashboard. A connector that throws is a site that looks
     * offline, which is a far worse outcome for something whose whole job is reporting liveness.
     *
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
                'Manager Connector could not read a field: ' . $e->getMessage(),
                'manager-connector',
            );

            return $fallback;
        }
    }
}

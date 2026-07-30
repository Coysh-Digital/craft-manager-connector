<?php

/**
 * Manager Connector plugin for Craft CMS 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

declare(strict_types=1);

namespace coyshdigital\managerconnector\services;

use coyshdigital\managerprotocol\SchemaValidator;
use Craft;
use craft\base\Component;
use Throwable;

/**
 * Builds the update report.
 *
 * Asks Craft what updates are available and reduces the answer to what the platform is allowed to
 * know: whether an update exists, how far behind the site is, and whether any release in between is
 * flagged critical.
 *
 * What it deliberately drops is the interesting part. Craft's update data includes release notes, and
 * those describe, in detail, what a given version fixes. Forwarding them would put a description of
 * an unpatched vulnerability, attached to a named site, into a dashboard and its database — so the
 * schema has no field for them and this class never reads them.
 *
 * The outbound request Craft makes to its own update service is the site checking its own updates. It
 * is not the arbitrary HTTP that invariant 8 forbids: no part of it is influenced by the job.
 */
class UpdatesReporter extends Component
{
    /**
     * @return array{payload: array<string, mixed>, problems: list<string>}
     */
    public function buildValidated(bool $force = false): array
    {
        $payload = $this->build($force);

        return [
            'payload' => $payload,
            'problems' => SchemaValidator::forSchema('updates.v1')->validate($payload),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function build(bool $force = false): array
    {
        $updates = Craft::$app->getUpdates()->getUpdates($force);

        return [
            'schema_version' => 'updates.v1',
            'checked_at' => time(),
            'craft' => $this->craft($updates),
            'plugins' => $this->plugins($updates),
            'php' => $this->php(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function craft(mixed $updates): array
    {
        $current = Craft::$app->getVersion();

        $update = $updates->cms ?? null;

        if ($update === null) {
            return ['current' => $current, 'latest' => $current, 'update_available' => false];
        }

        $latest = (string) $this->safely(static fn(): string => (string) $update->getLatest()?->version, $current);

        return array_filter([
            'current' => $current,
            'latest' => $latest === '' ? $current : $latest,
            'update_available' => $this->hasUpdate($update),
            'releases_behind' => $this->releaseCount($update),
            'security_release_available' => $this->hasCriticalRelease($update),
            'latest_is_breaking' => $this->safely(
                static fn(): bool => ($update->status ?? '') === 'breakpoint',
                false,
            ),
        ], static fn(mixed $value): bool => $value !== null);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function plugins(mixed $updates): array
    {
        $installed = Craft::$app->getPlugins()->getAllPluginInfo();
        $reported = [];

        foreach ($updates->plugins ?? [] as $handle => $update) {
            $info = $installed[$handle] ?? null;

            $current = (string) ($info['version'] ?? 'unknown');
            $latest = (string) $this->safely(static fn(): string => (string) $update->getLatest()?->version, $current);

            $reported[] = array_filter([
                'handle' => (string) $handle,
                'name' => isset($info['name']) ? mb_substr((string) $info['name'], 0, 191) : null,
                'current' => $current,
                'latest' => $latest === '' ? $current : $latest,
                'update_available' => $this->hasUpdate($update),
                'releases_behind' => $this->releaseCount($update),
                'security_release_available' => $this->hasCriticalRelease($update),
                'abandoned' => $this->safely(static fn(): bool => (bool) ($update->abandoned ?? false), false),
            ], static fn(mixed $value): bool => $value !== null);
        }

        return array_slice($reported, 0, 250);
    }

    /**
     * Whether the runtime itself is still supported.
     *
     * Not read from a remote service: an end-of-life date is a fact about PHP, and asking a third
     * party for it would be an outbound request that earns nothing.
     *
     * @return array<string, mixed>
     */
    private function php(): array
    {
        // Security-support end dates, from php.net's own schedule.
        $schedule = [
            '8.1' => ['2025-12-31', true],
            '8.2' => ['2026-12-31', false],
            '8.3' => ['2027-12-31', false],
            '8.4' => ['2028-12-31', false],
            '8.5' => ['2029-12-31', false],
        ];

        $branch = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

        if (!isset($schedule[$branch])) {
            return ['current' => PHP_VERSION];
        }

        [$until, $knownEol] = $schedule[$branch];

        return [
            'current' => PHP_VERSION,
            'security_support_until' => $until,
            'end_of_life' => $knownEol || strtotime($until) < time(),
        ];
    }

    private function hasUpdate(mixed $update): bool
    {
        return $this->safely(
            static fn(): bool => ($update->status ?? 'up-to-date') !== 'up-to-date'
                && $update->getLatest() !== null,
            false,
        );
    }

    private function releaseCount(mixed $update): ?int
    {
        return $this->safely(
            static fn(): ?int => is_array($update->releases ?? null) ? count($update->releases) : null,
            null,
        );
    }

    /**
     * Whether any release between installed and latest is flagged critical.
     *
     * This single boolean is what decides whether a site is urgent. Only the flag crosses the wire —
     * never the note explaining what the release fixes.
     */
    private function hasCriticalRelease(mixed $update): bool
    {
        return $this->safely(static function() use ($update): bool {
            foreach ($update->releases ?? [] as $release) {
                if ((bool) ($release->critical ?? false)) {
                    return true;
                }
            }

            return false;
        }, false);
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
                'Manager Connector could not read update information: ' . $e->getMessage(),
                'manager-connector',
            );

            return $fallback;
        }
    }
}

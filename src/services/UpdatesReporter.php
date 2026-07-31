<?php

/**
 * Manager Connector plugin for Craft CMS 4.x and 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

declare(strict_types=1);

namespace coyshdigital\managerconnector\services;

use coyshdigital\managerprotocol\SchemaValidator;
use Craft;
use craft\base\Component;
use DateTimeInterface;
use Throwable;

/**
 * Builds the update report.
 *
 * Asks Craft what updates are available and reduces the answer to what the platform needs: whether an
 * update exists, how far behind the site is, whether any release in between is flagged critical, and
 * — since `updates.v2` — what those releases actually say.
 *
 * That last part used to be the thing this class most deliberately withheld, and the reasoning has
 * been moved rather than dropped. The old comment here said forwarding release notes would put a
 * description of an unpatched vulnerability, attached to a named site, into a dashboard. The half of
 * that which is true is "attached to a named site"; the notes themselves are public, the Craft Plugin
 * Store hands them to anyone who asks, and this site already has a copy — which is exactly why they
 * can be sent without making a single request to anybody. What was never safe was the association,
 * and where that association gets stored is a decision made at the other end. `updates.v2.json` states
 * the obligation in its own description, and the platform is where it is enforced.
 *
 * Notes are sent only for a plugin that actually has an update available — there is nothing to
 * describe otherwise — and are bounded twice over: ten releases per plugin, and a budget across the
 * whole report, so a fleet running two hundred outdated plugins produces a report somebody can
 * receive.
 *
 * The outbound request Craft makes to its own update service is the site checking its own updates. It
 * is not the arbitrary HTTP that invariant 8 forbids: no part of it is influenced by the job.
 */
class UpdatesReporter extends Component
{
    /**
     * The most releases described for any one plugin. Matches the schema's own cap.
     */
    private const MAX_RELEASES_PER_PLUGIN = 10;

    /**
     * The longest single release note. Also the schema's cap — a note longer than this is truncated
     * here rather than rejected on arrival, because a report that fails validation tells the operator
     * nothing about their updates.
     */
    private const MAX_NOTE_CHARACTERS = 4000;

    /**
     * How much release note text the whole report may carry.
     *
     * The per-plugin caps alone permit 250 plugins × 10 releases × 4,000 characters, which is a body
     * measured in megabytes and a bad thing to point at somebody's inbound endpoint. In practice a
     * site has a handful of outdated plugins and never comes near this; a site that does simply stops
     * describing the rest, which the platform renders as no notes rather than as an error.
     */
    private const NOTE_BUDGET_CHARACTERS = 200000;

    /**
     * @return array{payload: array<string, mixed>, problems: list<string>}
     */
    public function buildValidated(bool $force = false): array
    {
        $payload = $this->build($force);

        return [
            'payload' => $payload,
            'problems' => SchemaValidator::forSchema('updates.v2')->validate($payload),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function build(bool $force = false): array
    {
        $updates = Craft::$app->getUpdates()->getUpdates($force);

        return [
            'schema_version' => 'updates.v2',
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
        $budget = self::NOTE_BUDGET_CHARACTERS;

        foreach ($updates->plugins ?? [] as $handle => $update) {
            $info = $installed[$handle] ?? null;

            $current = (string) ($info['version'] ?? 'unknown');
            $latest = (string) $this->safely(static fn(): string => (string) $update->getLatest()?->version, $current);
            $hasUpdate = $this->hasUpdate($update);

            $reported[] = array_filter([
                'handle' => (string) $handle,
                'name' => isset($info['name']) ? mb_substr((string) $info['name'], 0, 191) : null,
                'current' => $current,
                'latest' => $latest === '' ? $current : $latest,
                'update_available' => $hasUpdate,
                'releases_behind' => $this->releaseCount($update),
                'security_release_available' => $this->hasCriticalRelease($update),
                'abandoned' => $this->safely(static fn(): bool => (bool) ($update->abandoned ?? false), false),

                // Only for a plugin that has somewhere to go. array_filter drops the key entirely
                // when there is nothing to say, so an up-to-date plugin is the same shape it was
                // under v1.
                'releases' => $hasUpdate ? ($this->releases($update, $budget) ?: null) : null,
            ], static fn(mixed $value): bool => $value !== null);
        }

        return array_slice($reported, 0, 250);
    }

    /**
     * What the releases between installed and latest actually say.
     *
     * Craft has already fetched these — they are the same objects {@see hasCriticalRelease} walks for
     * its one boolean, and reading the rest of each one costs nothing and asks nobody. The whole
     * method is inside safely(): a plugin whose release data is shaped unexpectedly loses its notes,
     * not its row.
     *
     * @param  int  $budget  Decremented as notes are taken, so the cap applies across the report
     *                       rather than per plugin.
     * @return list<array<string, mixed>>
     */
    private function releases(mixed $update, int &$budget): array
    {
        return $this->safely(function() use ($update, &$budget): array {
            $releases = [];

            foreach ($update->releases ?? [] as $release) {
                if (count($releases) >= self::MAX_RELEASES_PER_PLUGIN) {
                    break;
                }

                $version = (string) ($release->version ?? '');

                if ($version === '') {
                    // Notes with no version cannot be filtered against what the site is running, so
                    // they would be shown to everybody regardless of whether they apply.
                    continue;
                }

                $notes = trim((string) ($release->notes ?? ''));

                if ($notes !== '') {
                    $notes = mb_substr($notes, 0, self::MAX_NOTE_CHARACTERS);

                    if (mb_strlen($notes) > $budget) {
                        $notes = '';
                    } else {
                        $budget -= mb_strlen($notes);
                    }
                }

                $releases[] = array_filter([
                    'version' => mb_substr($version, 0, 32),
                    'notes' => $notes === '' ? null : $notes,
                    'critical' => (bool) ($release->critical ?? false),
                    'date' => $this->releaseDate($release),
                ], static fn(mixed $value): bool => $value !== null);
            }

            return $releases;
        }, []);
    }

    /**
     * The release date, as something displayable.
     *
     * Craft hands this over as a DateTime on some paths and a string on others, and it is only ever
     * shown, never compared — so it crosses as a string and anything unrecognisable is simply left
     * out.
     */
    private function releaseDate(mixed $release): ?string
    {
        $date = $release->date ?? null;

        if ($date instanceof DateTimeInterface) {
            return $date->format('Y-m-d');
        }

        if (is_string($date) && $date !== '') {
            return mb_substr($date, 0, 32);
        }

        return null;
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

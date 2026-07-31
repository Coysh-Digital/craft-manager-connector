<?php

/**
 * Manager Connector plugin for Craft CMS 4.x and 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

declare(strict_types=1);

namespace coyshdigital\managerconnector\services;

use coyshdigital\managerconnector\Plugin;
use craft\base\Component;
use RuntimeException;

/**
 * The four things this connector does, in one place.
 *
 * They can be started from three directions — a console command, Craft's queue, or ordinary web traffic
 * when the schedule is due — and each needs the same work done. Holding the work here means the three
 * cannot drift: a fix to what "report" means is a fix to all of them.
 *
 * Each method returns a short description of what happened, or throws. Nothing here writes to stdout or
 * touches a response, because two of the three callers have neither.
 */
class Tasks extends Component
{
    public const HEARTBEAT = 'heartbeat';

    public const REPORT = 'report';

    public const UPDATES = 'updates';

    public const JOBS = 'jobs';

    public const SYSTEM = 'system';

    public const LOGINS = 'logins';

    /**
     * Every task that may be run, and how often the schedule considers each due.
     *
     * A closed set, and the reason it is a constant rather than configuration: the queue job takes a
     * task name, and a name that could be anything would make the job a way to reach anything.
     *
     * @return array<string, int> task => seconds between runs
     */
    public static function schedule(): array
    {
        return [
            // Cheap, and what makes a site look alive. Frequent.
            self::HEARTBEAT => 300,

            // Collects work queued in Manager, including the Refresh button. Frequent for the same
            // reason: a button that appears to do nothing for an hour is a button people press again.
            self::JOBS => 300,

            // Versions and plugin lists. These change when somebody deploys, not by the minute.
            self::REPORT => 3600,

            // Sign-in counters. Frequent enough that an attack in progress is visible while it is in
            // progress, and it is one indexed query.
            self::LOGINS => 1800,

            // Walks the asset volumes, so this is the expensive one. Six-hourly: disk usage moves
            // over days, and a directory walk every hour on a million-file volume is a cost the site
            // pays for a number nobody reads that often.
            self::SYSTEM => 21600,

            // Asks a third party, so daily is plenty.
            self::UPDATES => 86400,
        ];
    }

    public static function isKnown(string $task): bool
    {
        return array_key_exists($task, self::schedule());
    }

    /**
     * Run one task by name.
     *
     * A match over constants rather than a method name built from the argument. The argument reaches
     * here from a queue payload, and a payload that could name a method would be a way to call one.
     *
     * @throws RuntimeException
     */
    public function run(string $task): string
    {
        return match ($task) {
            self::HEARTBEAT => $this->heartbeat(),
            self::REPORT => $this->report(),
            self::UPDATES => $this->updates(),
            self::SYSTEM => $this->system(),
            self::LOGINS => $this->logins(),
            self::JOBS => $this->jobs(),
            default => throw new RuntimeException("'{$task}' is not a task this connector implements."),
        };
    }

    /**
     * A task the platform has not given this site permission to perform.
     *
     * Returned, not thrown, and the distinction is the whole point of this method.
     *
     * Being refused is the permission system working. Manager's own interface says as much — "a
     * security rule whose capability is missing is skipped rather than passed" — and a site owner
     * who declines to report sign-in counters has made a choice, not a mistake. Throwing turned that
     * choice into a failed queue job every thirty minutes, in *their* control panel, from the plugin
     * that is supposed to be watching their site. The first evidence of this product many people
     * would ever see is a growing list of failures.
     *
     * It is still recorded: {@see \coyshdigital\managerconnector\jobs\RunTask} logs the outcome, so
     * "why is nothing being reported" has an answer in the log without also being an alarm.
     *
     * Not sticky, either. The capability list is refreshed from the platform's response to the jobs
     * task, which runs every five minutes, so granting one takes effect on its own.
     */
    private function skipped(string $capability): string
    {
        return "Skipped: the platform has not granted {$capability} to this site.";
    }

    public function heartbeat(): string
    {
        $this->requireActiveConnection();

        Plugin::getInstance()->client->post('/api/connector/v1/heartbeat', []);

        return 'Heartbeat sent.';
    }

    public function report(): string
    {
        $this->requireActiveConnection();

        $plugin = Plugin::getInstance();

        if (!$plugin->connection->hasCapability('inventory:read')) {
            return $this->skipped('inventory:read');
        }

        ['payload' => $payload, 'problems' => $problems] = $plugin->reporter->buildValidated();

        // Checked here as well as on arrival. The platform validates again regardless, but finding it
        // now names the field rather than producing a rejection somebody has to go looking for.
        if ($problems !== []) {
            throw new RuntimeException(
                'this report does not satisfy the agreed schema: ' . implode('; ', array_slice($problems, 0, 5))
            );
        }

        $plugin->client->post('/api/connector/v1/inventory', $payload);

        return 'Inventory reported.';
    }

    public function updates(bool $force = false): string
    {
        $this->requireActiveConnection();

        $plugin = Plugin::getInstance();

        if (!$plugin->connection->hasCapability('updates:read')) {
            return $this->skipped('updates:read');
        }

        ['payload' => $payload, 'problems' => $problems] = $plugin->updates->buildValidated($force);

        if ($problems !== []) {
            throw new RuntimeException(
                'this report does not satisfy the agreed schema: ' . implode('; ', array_slice($problems, 0, 5))
            );
        }

        $plugin->client->post('/api/connector/v1/updates', $payload);

        return 'Updates reported.';
    }

    /**
     * Disk usage, PHP limits and sampled response timings.
     */
    public function system(): string
    {
        $this->requireActiveConnection();

        $plugin = Plugin::getInstance();

        if (!$plugin->connection->hasCapability('runtime:read')) {
            return $this->skipped('runtime:read');
        }

        ['payload' => $payload, 'problems' => $problems] = $plugin->system->buildValidated();

        if ($problems !== []) {
            throw new RuntimeException(
                'this report does not satisfy the agreed schema: ' . implode('; ', array_slice($problems, 0, 5))
            );
        }

        $plugin->client->post('/api/connector/v1/system', $payload);

        return 'Runtime reported.';
    }

    /**
     * Counts of failed control-panel sign-ins.
     */
    public function logins(): string
    {
        $this->requireActiveConnection();

        $plugin = Plugin::getInstance();

        if (!$plugin->connection->hasCapability('logins:read')) {
            return $this->skipped('logins:read');
        }

        ['payload' => $payload, 'problems' => $problems] = $plugin->logins->buildValidated();

        if ($problems !== []) {
            throw new RuntimeException(
                'this report does not satisfy the agreed schema: ' . implode('; ', array_slice($problems, 0, 5))
            );
        }

        $plugin->client->post('/api/connector/v1/logins', $payload);

        return 'Sign-in counters reported.';
    }

    public function jobs(): string
    {
        $this->requireActiveConnection();

        $tally = Plugin::getInstance()->jobs->run();

        if ($tally['claimed'] === 0) {
            return 'Nothing to do.';
        }

        return sprintf(
            '%d claimed: %d succeeded, %d failed, %d refused.',
            $tally['claimed'],
            $tally['succeeded'],
            $tally['failed'],
            $tally['refused'],
        );
    }

    /**
     * @throws RuntimeException
     */
    private function requireActiveConnection(): void
    {
        $connection = Plugin::getInstance()->connection;

        if (!$connection->isPaired()) {
            throw new RuntimeException('this site is not paired with a Manager platform');
        }

        if (!$connection->isActive()) {
            throw new RuntimeException(
                'this pairing is waiting for confirmation in Manager, so nothing is reported yet'
            );
        }
    }
}

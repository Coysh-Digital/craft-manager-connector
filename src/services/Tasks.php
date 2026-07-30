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
            self::JOBS => $this->jobs(),
            default => throw new RuntimeException("'{$task}' is not a task this connector implements."),
        };
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

        if (! $plugin->connection->hasCapability('inventory:read')) {
            throw new RuntimeException('the platform has not granted inventory:read to this site');
        }

        ['payload' => $payload, 'problems' => $problems] = $plugin->reporter->buildValidated();

        // Checked here as well as on arrival. The platform validates again regardless, but finding it
        // now names the field rather than producing a rejection somebody has to go looking for.
        if ($problems !== []) {
            throw new RuntimeException(
                'this report does not satisfy the agreed schema: '.implode('; ', array_slice($problems, 0, 5))
            );
        }

        $plugin->client->post('/api/connector/v1/inventory', $payload);

        return 'Inventory reported.';
    }

    public function updates(bool $force = false): string
    {
        $this->requireActiveConnection();

        $plugin = Plugin::getInstance();

        if (! $plugin->connection->hasCapability('updates:read')) {
            throw new RuntimeException('the platform has not granted updates:read to this site');
        }

        ['payload' => $payload, 'problems' => $problems] = $plugin->updates->buildValidated($force);

        if ($problems !== []) {
            throw new RuntimeException(
                'this report does not satisfy the agreed schema: '.implode('; ', array_slice($problems, 0, 5))
            );
        }

        $plugin->client->post('/api/connector/v1/updates', $payload);

        return 'Updates reported.';
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

        if (! $connection->isPaired()) {
            throw new RuntimeException('this site is not paired with a Manager platform');
        }

        if (! $connection->isActive()) {
            throw new RuntimeException(
                'this pairing is waiting for confirmation in Manager, so nothing is reported yet'
            );
        }
    }
}

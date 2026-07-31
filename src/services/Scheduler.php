<?php

/**
 * Manager Connector plugin for Craft CMS 4.x and 5.x
 *
 * @link      https://managerforcraft.com
 * @copyright Copyright (c) Coysh Digital
 */

declare(strict_types=1);

namespace coyshdigital\managerconnector\services;

use coyshdigital\managerconnector\jobs\RunTask;
use coyshdigital\managerconnector\Plugin;
use Craft;
use craft\base\Component;
use Throwable;

/**
 * Runs the schedule from ordinary web traffic, for sites with no cron.
 *
 * Plenty of Craft sites live on hosting where nobody can add a cron entry. Requiring one would mean
 * this plugin simply does not work there, which is an exclusion rather than a limitation — so traffic
 * to the site drives the schedule instead.
 *
 * Three properties make that safe to have switched on:
 *
 *  - **It is not an endpoint.** This is a listener on requests that were happening anyway. It reads
 *    nothing from the request, accepts no parameters, and there is no URL that reaches it. Invariant 4
 *    is about a public inbound *management endpoint*; this is neither inbound nor an endpoint.
 *  - **Traffic cannot amplify it.** Whether a site gets ten requests an hour or ten thousand, each task
 *    fires at most once per interval. The claim is an atomic cache add, so two simultaneous requests
 *    cannot both win it.
 *  - **The visitor waits for nothing.** All it does is push a queue job. The work happens in the queue.
 *
 * It is not a replacement for cron on a site that has cron. A quiet site reports less often, and a site
 * with no traffic overnight reports nothing overnight — Manager will notice the gap, correctly. Cron is
 * more predictable and the documentation says so.
 */
class Scheduler extends Component
{
    /**
     * Prefix on the cache keys that hold each task off until it is due.
     */
    private const CLAIM_PREFIX = 'manager-connector.due.';

    /**
     * Push whatever is due, at most one task per request.
     *
     * One per request rather than all of them: pushing four jobs from a single visitor's request makes
     * that request the slowest of the day for no reason, and the next request along will take the next
     * task a moment later.
     *
     * @return string|null the task pushed, if any
     */
    public function runDue(): ?string
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->getSettings()->webTrigger) {
            return null;
        }

        // Nothing to do until the site has an identity to report with.
        if (!$plugin->connection->isActive()) {
            return null;
        }

        foreach (Tasks::schedule() as $task => $interval) {
            if (!$this->claim($task, $interval)) {
                continue;
            }

            try {
                Craft::$app->getQueue()->push(new RunTask(['task' => $task]));
            } catch (Throwable $e) {
                // A queue that will not accept a job is a site problem, not a reporting problem. Logged
                // and dropped: throwing here would turn it into a 500 on somebody's page.
                Craft::warning(
                    'Manager Connector could not queue ' . $task . ': ' . $e->getMessage(),
                    'manager-connector',
                );

                return null;
            }

            return $task;
        }

        return null;
    }

    /**
     * Claim a task for this interval, atomically.
     *
     * `add()` writes only when the key is absent, so exactly one of any number of concurrent requests
     * gets true. A read-then-write would let a burst of traffic push the same task several times.
     */
    private function claim(string $task, int $interval): bool
    {
        try {
            return Craft::$app->getCache()->add(self::CLAIM_PREFIX . $task, 1, $interval);
        } catch (Throwable) {
            // No usable cache means no way to throttle, and an unthrottled trigger would fire on every
            // request. Doing nothing is the safe failure.
            return false;
        }
    }

    /**
     * When each task is next due, for the status command.
     *
     * @return array<string, bool> task => due now
     */
    public function due(): array
    {
        $due = [];

        foreach (array_keys(Tasks::schedule()) as $task) {
            try {
                $due[$task] = Craft::$app->getCache()->get(self::CLAIM_PREFIX . $task) === false;
            } catch (Throwable) {
                $due[$task] = false;
            }
        }

        return $due;
    }
}

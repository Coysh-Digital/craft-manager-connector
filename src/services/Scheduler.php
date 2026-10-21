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
 * this plugin simply does not work there, which is an exclusion rather than a limitation - so traffic
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
 * with no traffic overnight reports nothing overnight - Manager will notice the gap, correctly. Cron is
 * more predictable and the documentation says so.
 */
class Scheduler extends Component
{
    /**
     * Prefix on the cache keys that hold each task off until it is due.
     */
    private const CLAIM_PREFIX = 'manager-connector.due.';

    /**
     * What the single cron entry does every time it fires, whatever the schedule says.
     *
     * The two five-minute tasks. A cron line that fires every five minutes already *is* their schedule,
     * so asking the cache whether they are due would only let a claim lost to web traffic skip a
     * heartbeat the cron was about to send.
     */
    private const ALWAYS = [Tasks::HEARTBEAT, Tasks::JOBS];

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
     * Which tasks the single cron entry should run now, claiming the ones that are due.
     *
     * Everything that decides is in this method and none of it touches Craft: `$claim` is handed a task
     * and a lifetime in seconds and answers whether this caller won it. In production that is the same
     * atomic cache add the web trigger uses, so cron and web traffic share one set of keys and cannot
     * both fire a task in the same interval. In a test it is a closure.
     *
     * The lifetime is the interval less `$grace`. Cron fires on the minute, and a run that starts a few
     * seconds late would otherwise find the previous claim still standing, skip the task, and push it a
     * whole interval - an hourly report arriving every other hour, on a machine that is merely busy.
     *
     * `$force` takes every task whatever `$claim` says. The claim is still made, so that a forced run
     * holds the web trigger off exactly as an ordinary one would.
     *
     * The heartbeat goes first, so a site is seen alive even if a later task is slow, and `jobs` goes
     * last because it is the one that can run for hours: it is where a backup happens, inline, in this
     * process, and nothing queued behind it would run until it finished.
     *
     * @param callable(string, int): bool $claim
     * @return list<string> tasks to run, in the order to run them
     */
    public static function selectDue(callable $claim, bool $force = false, int $grace = 60): array
    {
        $due = [];

        foreach (Tasks::schedule() as $task => $interval) {
            if (in_array($task, self::ALWAYS, true)) {
                continue;
            }

            $won = $claim($task, max(1, $interval - $grace));

            if ($won || $force) {
                $due[] = $task;
            }
        }

        return [Tasks::HEARTBEAT, ...$due, Tasks::JOBS];
    }

    /**
     * Claim whatever the single cron entry should run now. See {@see self::selectDue()}.
     *
     * The always-run tasks are marked as having run, so the web trigger does not push the same
     * heartbeat a moment after cron has sent it.
     *
     * @return list<string>
     */
    public function claimDue(int $grace = 60, bool $force = false): array
    {
        foreach (self::ALWAYS as $task) {
            $this->markRan($task, $grace);
        }

        return self::selectDue(fn(string $task, int $ttl): bool => $this->claim($task, $ttl), $force, $grace);
    }

    /**
     * Claim a task for the next `$ttl` seconds, atomically.
     *
     * `add()` writes only when the key is absent, so exactly one of any number of concurrent requests
     * gets true. A read-then-write would let a burst of traffic push the same task several times.
     */
    private function claim(string $task, int $ttl): bool
    {
        try {
            return Craft::$app->getCache()->add(self::CLAIM_PREFIX . $task, 1, $ttl);
        } catch (Throwable) {
            // No usable cache means no way to throttle, and an unthrottled trigger would fire on every
            // request. Doing nothing is the safe failure.
            return false;
        }
    }

    /**
     * Hold a task off as though it had just run on schedule.
     *
     * Called when something outside the schedule has already pushed a task - today only the nudge
     * endpoint, which pushes `jobs` the moment the platform asks it to. Without this the ordinary
     * schedule would push the same task again on the next request that found it due, so a nudge would
     * routinely be followed by a redundant claim a moment later.
     *
     * Writes the same claim key {@see self::claim()} writes, so the two cannot disagree about when a
     * task is next due. `$grace` shortens the hold in the same way and for the same reason
     * {@see self::selectDue()} does.
     */
    public function markRan(string $task, int $grace = 0): void
    {
        $interval = Tasks::schedule()[$task] ?? null;

        if ($interval === null) {
            return;
        }

        try {
            Craft::$app->getCache()->set(self::CLAIM_PREFIX . $task, 1, max(1, $interval - $grace));
        } catch (Throwable) {
            // Only costs a duplicate claim on the next request. Nothing to report.
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

<?php

/**
 * Manager Connector plugin for Craft CMS 4.x and 5.x
 * @link      https://managerforcraft.com
 * @copyright Copyright (c) Coysh Digital
 */

declare(strict_types=1);

namespace coyshdigital\managerconnector\Tests;

use coyshdigital\managerconnector\services\Scheduler;
use coyshdigital\managerconnector\services\Tasks;
use PHPUnit\Framework\TestCase;

/**
 * Which tasks the single cron entry runs.
 *
 * `Scheduler::selectDue()` is the whole decision, and it is a function of a claim callable so that it
 * can be asked without a Craft application or a cache. What these cases pin down is the part that is
 * easy to get quietly wrong: a task that is not due must not run, a task whose claim was lost to web
 * traffic must not run, and the two that run every time must run without asking.
 */
final class SchedulerTest extends TestCase
{
    public function testWithNothingClaimedOnlyTheAlwaysRunTasksRun(): void
    {
        $due = Scheduler::selectDue(static fn(string $task, int $ttl): bool => false);

        self::assertSame(['heartbeat', 'jobs'], $due);
    }

    public function testEveryOtherTaskRunsWhenItsClaimIsWon(): void
    {
        $due = Scheduler::selectDue(static fn(string $task, int $ttl): bool => true);

        self::assertSame(['heartbeat', 'report', 'logins', 'system', 'updates', 'jobs'], $due);
    }

    public function testOnlyTheTasksWhoseClaimIsWonRun(): void
    {
        $due = Scheduler::selectDue(static fn(string $task, int $ttl): bool => $task === Tasks::LOGINS);

        self::assertSame(['heartbeat', 'logins', 'jobs'], $due);
    }

    public function testTheAlwaysRunTasksAreNeverClaimed(): void
    {
        $asked = [];

        Scheduler::selectDue(static function(string $task, int $ttl) use (&$asked): bool {
            $asked[] = $task;

            return false;
        });

        self::assertSame(['report', 'logins', 'system', 'updates'], $asked);
    }

    public function testTheClaimLastsTheIntervalLessTheGrace(): void
    {
        $ttls = [];

        Scheduler::selectDue(static function(string $task, int $ttl) use (&$ttls): bool {
            $ttls[$task] = $ttl;

            return false;
        });

        self::assertSame(
            ['report' => 3540, 'logins' => 1740, 'system' => 21540, 'updates' => 86340],
            $ttls,
        );
    }

    public function testTheGraceIsAdjustable(): void
    {
        $ttls = [];

        Scheduler::selectDue(static function(string $task, int $ttl) use (&$ttls): bool {
            $ttls[$task] = $ttl;

            return false;
        }, false, 120);

        self::assertSame(3480, $ttls[Tasks::REPORT]);
    }

    public function testAGraceLongerThanTheIntervalStillLeavesAClaimThatExpires(): void
    {
        $ttls = [];

        Scheduler::selectDue(static function(string $task, int $ttl) use (&$ttls): bool {
            $ttls[] = $ttl;

            return false;
        }, false, 999999);

        // A zero or negative lifetime means "never expires" to some cache backends, which would
        // switch the task off for good.
        self::assertSame([1, 1, 1, 1], $ttls);
    }

    public function testForceRunsEverythingEvenWhenEveryClaimIsLost(): void
    {
        $due = Scheduler::selectDue(static fn(string $task, int $ttl): bool => false, true);

        self::assertSame(['heartbeat', 'report', 'logins', 'system', 'updates', 'jobs'], $due);
    }

    public function testForceStillMakesTheClaimsSoTheWebTriggerIsHeldOff(): void
    {
        $asked = [];

        Scheduler::selectDue(static function(string $task, int $ttl) use (&$asked): bool {
            $asked[] = $task;

            return false;
        }, true);

        self::assertSame(['report', 'logins', 'system', 'updates'], $asked);
    }

    public function testHeartbeatIsFirstAndJobsIsLast(): void
    {
        $due = Scheduler::selectDue(static fn(string $task, int $ttl): bool => true);

        self::assertSame(Tasks::HEARTBEAT, $due[0]);
        self::assertSame(Tasks::JOBS, $due[array_key_last($due)]);
    }

    public function testNothingOutsideTheClosedRegistryCanBeSelected(): void
    {
        $due = Scheduler::selectDue(static fn(string $task, int $ttl): bool => true, true);

        foreach ($due as $task) {
            self::assertTrue(Tasks::isKnown($task), "'{$task}' is not in the task registry.");
        }

        self::assertSame(count(Tasks::schedule()), count($due));
    }
}

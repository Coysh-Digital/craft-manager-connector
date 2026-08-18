<?php

/**
 * Manager Connector plugin for Craft CMS 4.x and 5.x
 * @link      https://managerforcraft.com
 * @copyright Copyright (c) Coysh Digital
 */

declare(strict_types=1);

namespace coyshdigital\managerconnector\Tests;

use coyshdigital\managerconnector\services\Tasks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The task registry is closed, and closed is the security property.
 *
 * A task name arrives from a queue payload. `Tasks::run()` matches it against constants rather than
 * building a method name from it, and `isKnown()` is the gate in front of that. The registry being a
 * constant rather than configuration is the reason a compromised platform cannot make a site do
 * something new: there is no name it can send that reaches anything not listed here.
 *
 * These cases are mostly about what `isKnown()` says *no* to. A gate is not interesting when handed
 * the six names it expects; it is interesting when handed a method name, a path, or a near miss in
 * the wrong case, and the failure mode of a gate that is too generous is silent.
 */
final class TaskRegistryTest extends TestCase
{
    public function testTheRegistryIsExactlyTheSixDeclaredTasks(): void
    {
        // Named individually rather than derived from the class, so that adding a seventh is a
        // deliberate edit here and not something a test absorbs quietly.
        self::assertSame(
            ['heartbeat', 'jobs', 'report', 'logins', 'system', 'updates'],
            array_keys(Tasks::schedule()),
        );
    }

    #[DataProvider('knownTasks')]
    public function testAcceptsEachDeclaredTask(string $task): void
    {
        self::assertTrue(Tasks::isKnown($task));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function knownTasks(): array
    {
        return array_combine(
            array_keys(Tasks::schedule()),
            array_map(static fn(string $task): array => [$task], array_keys(Tasks::schedule())),
        );
    }

    #[DataProvider('rejectedNames')]
    public function testRefusesAnythingElse(string $task, string $why): void
    {
        self::assertFalse(Tasks::isKnown($task), $why);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function rejectedNames(): array
    {
        return [
            'empty' => ['', 'An absent payload field arrives as an empty string.'],
            'whitespace' => ['  ', 'Nothing is trimmed on the way in, and it should not be.'],
            'wrong case' => ['Heartbeat', 'array_key_exists is case sensitive, and this asserts that stays true.'],
            'trailing space' => ['heartbeat ', 'A near miss must miss.'],
            'a method on the class' => ['run', 'run() is public. The gate is what stops a payload naming it.'],
            'a magic method' => ['__construct', 'The reason run() matches constants rather than building a method name.'],
            'a path' => ['../../etc/passwd', 'Task names reach no filesystem, and this is the assertion that says so.'],
            'a namespaced class' => ['coyshdigital\\managerconnector\\services\\Tasks', 'Not a name anything here resolves.'],
            'a shell fragment' => ['heartbeat; rm -rf /', 'Nothing shells out, and a compound name is still one name.'],
            'a plausible sounding task' => ['backup', 'Backups are a platform-issued job, not a scheduled task, and the registry is the difference.'],
            'a plausible plural' => ['heartbeats', 'A near miss must miss.'],
        ];
    }

    public function testEveryIntervalIsAPositiveNumberOfSeconds(): void
    {
        foreach (Tasks::schedule() as $task => $seconds) {
            self::assertGreaterThan(0, $seconds, "{$task} would otherwise be due on every request.");
        }
    }
}

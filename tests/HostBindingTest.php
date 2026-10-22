<?php

/**
 * Manager Connector plugin for Craft CMS 4.x and 5.x
 * @link      https://managerforcraft.com
 * @copyright Copyright (c) Coysh Digital
 */

declare(strict_types=1);

namespace coyshdigital\managerconnector\Tests;

use coyshdigital\managerconnector\services\Connection;
use PHPUnit\Framework\TestCase;

/**
 * When a pairing belongs to the environment looking at it.
 *
 * A production backup restored into development carries the pairing with it, and `hostMatches()` is the
 * whole decision about whether that copy stays quiet. It is a pure function so it can be asked without a
 * Craft application. What these cases pin down is both directions of getting it wrong: a copy that
 * reports as production overwrites live data, and a production site that goes silent because a host
 * could not be worked out is a monitoring plugin that stopped monitoring.
 */
final class HostBindingTest extends TestCase
{
    public function testTheSameHostBelongsHere(): void
    {
        self::assertTrue(Connection::hostMatches('example.com', 'example.com'));
    }

    public function testCaseDoesNotMakeADifferentHost(): void
    {
        self::assertTrue(Connection::hostMatches('Example.COM', 'example.com'));
    }

    public function testADifferentHostIsACopy(): void
    {
        self::assertFalse(Connection::hostMatches('example.com', 'example.ddev.site'));
    }

    public function testWwwAndTheApexAreDeliberatelyDifferent(): void
    {
        self::assertFalse(Connection::hostMatches('example.com', 'www.example.com'));
    }

    public function testAPairingWithNoRecordedHostIsNotBound(): void
    {
        self::assertTrue(Connection::hostMatches(null, 'example.ddev.site'));
        self::assertTrue(Connection::hostMatches('', 'example.ddev.site'));
    }

    public function testAnUnknownCurrentHostFailsOpen(): void
    {
        self::assertTrue(Connection::hostMatches('example.com', ''));
    }
}

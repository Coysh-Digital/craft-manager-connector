<?php

/**
 * Manager Connector plugin for Craft CMS 4.x and 5.x
 * @link      https://managerforcraft.com
 * @copyright Copyright (c) Coysh Digital
 */

declare(strict_types=1);

namespace coyshdigital\managerconnector\Tests;

use coyshdigital\managerconnector\services\Client;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Where an artifact is allowed to be sent.
 *
 * `bin/verify-invariants.php` proves the connector never *accepts* a destination as a parameter, which
 * is the property that matters: a platform response cannot name where a backup goes. What it cannot
 * prove is that the function deriving the destination from local state derives a sane one, because
 * that is behaviour rather than shape.
 *
 * The two failures worth naming, since they are what these cases are for. A URL this cannot parse must
 * produce an empty string and not a partial host, because the caller refuses on empty and half a
 * destination is a destination. And an address that is not a hostname at all - an IP, a bare label, a
 * host with credentials in front of it - must not acquire an `uploads.` prefix and become one.
 */
final class UploadDestinationTest extends TestCase
{
    #[DataProvider('hosts')]
    public function testDerivesTheUploadHost(string $platformUrl, string $expected, string $why): void
    {
        self::assertSame($expected, Client::uploadHostFor($platformUrl), $why);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function hosts(): array
    {
        return [
            'ordinary https' => [
                'https://manager.example-site.com', 'uploads.manager.example-site.com',
                'The whole rule is uploads. in front of the host an operator typed at pairing.',
            ],
            'path and query are not part of the host' => [
                'https://manager.example-site.com/admin?x=1', 'uploads.manager.example-site.com',
                'parse_url gives the host alone; anything else would put a path in a hostname.',
            ],
            'port is not part of the host' => [
                'https://manager.example-site.com:8443', 'uploads.manager.example-site.com',
                'A port belongs to the connection, not the name being resolved.',
            ],
            'case is normalised' => [
                'https://Manager.Example-Site.COM', 'uploads.manager.example-site.com',
                'Hostnames are case insensitive and the derived one is compared as a string.',
            ],
            'credentials in the URL do not reach the host' => [
                'https://user:pass@manager.example-site.com', 'uploads.manager.example-site.com',
                'parse_url separates userinfo; leaking it into a hostname would be an odd DNS lookup '
                . 'and a credential in a log.',
            ],
            'an IP address is not a hostname' => [
                'https://203.0.113.10', '',
                'An IP with uploads. in front of it is not a name anybody controls, and refusing is '
                . 'the only safe answer.',
            ],
            'a bare label is refused' => [
                'https://localhost', '',
                'uploads.localhost would resolve somewhere on a developer machine and nowhere useful '
                . 'in production.',
            ],
            'an underscore is not legal in a hostname' => [
                'https://manager_example.com', '',
                'The regex is the same bare-hostname rule the configured value is held to.',
            ],
            'a trailing dot is refused' => [
                'https://manager.example-site.com.', '',
                'Legal in DNS, but it is not the form the rest of this agrees on.',
            ],
            'a single trailing hyphen is refused' => [
                'https://manager-.example-site.com', '',
                'A label may not end in a hyphen.',
            ],
            'a URL with no host at all' => [
                'not a url', '',
                'Returns empty rather than guessing. The caller refuses on empty.',
            ],
            'an empty string' => [
                '', '',
                'The stored platform URL can be absent; that is not a reason to invent a destination.',
            ],
            'a scheme with nothing after it' => [
                'https://', '',
                'A half parsed URL must never become half a destination.',
            ],
        ];
    }

    public function testTakesNothingButTheUrl(): void
    {
        // The safety argument in the docblock rests on there being no other input, so a change that
        // gave this a second parameter - one a platform response could reach - would be the change
        // worth noticing. Asserted rather than assumed.
        $method = new \ReflectionMethod(Client::class, 'uploadHostFor');

        self::assertTrue($method->isStatic(), 'uploadHostFor must not depend on instance state.');
        self::assertSame(1, $method->getNumberOfParameters());
        self::assertSame('string', (string) $method->getParameters()[0]->getType());
    }
}

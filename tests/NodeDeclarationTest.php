<?php

/**
 * Manager Connector plugin for Craft CMS 4.x and 5.x
 * @link      https://managerforcraft.com
 * @copyright Copyright (c) Coysh Digital
 */

declare(strict_types=1);

namespace coyshdigital\managerconnector\Tests;

use coyshdigital\managerconnector\services\NodeDeclaration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which Node.js version a project declares, and when it declares nothing we are willing to repeat.
 *
 * `system.v4` carries this as two members that are both required and both nullable, and the schema
 * refuses anything outside a narrow character set. The connector has to agree with that schema
 * *before* sending, because a value the platform rejects does not get ignored - the whole runtime
 * report is refused, silently. So most of these cases are about what must come back as nothing: a
 * sentence, a path, an object where a string should be, a value one character too long.
 *
 * Every case that touches the filesystem works in a throwaway directory. Nothing here reads the
 * project this test runs in.
 */
final class NodeDeclarationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/node-declaration-' . bin2hex(random_bytes(6));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/{,.}*', GLOB_BRACE) ?: [] as $entry) {
            if (is_link($entry) || is_file($entry)) {
                unlink($entry);
            }
        }

        rmdir($this->root);
    }

    private function write(string $name, string $content): void
    {
        file_put_contents($this->root . '/' . $name, $content);
    }

    /**
     * @param array{declared: string|null, source: string|null} $expected
     */
    private function assertDetects(array $expected, string $why = ''): void
    {
        self::assertSame($expected, NodeDeclaration::detect($this->root), $why);
    }

    public function testNothingDeclaredIsTwoNulls(): void
    {
        $this->assertDetects(
            ['declared' => null, 'source' => null],
            'Both members are always present; null/null means "looked, nothing declared".',
        );
    }

    public function testARootThatDoesNotExistIsNothingDeclared(): void
    {
        self::assertSame(
            ['declared' => null, 'source' => null],
            NodeDeclaration::detect($this->root . '/missing'),
        );
        self::assertSame(['declared' => null, 'source' => null], NodeDeclaration::detect(''));
    }

    #[DataProvider('nvmrcFiles')]
    public function testReadsNvmrc(string $content, ?string $expected, string $why): void
    {
        $this->write('.nvmrc', $content);

        $this->assertDetects(
            ['declared' => $expected, 'source' => $expected === null ? null : '.nvmrc'],
            $why,
        );
    }

    /**
     * @return array<string, array{string, string|null, string}>
     */
    public static function nvmrcFiles(): array
    {
        return [
            'a v-prefixed version' => ["v20.11.0\n", 'v20.11.0', 'Kept as written. The v is a letter the schema allows.'],
            'a bare major' => ["20\n", '20', 'nvm resolves a major on its own.'],
            'an lts alias' => ["lts/iron\n", 'lts/iron', 'The one place a slash is allowed.'],
            'the latest lts alias' => ["lts/*\n", 'lts/*', 'The star is in the character class.'],
            'a bare codename' => ["iron\n", 'iron', 'nvm accepts codenames without lts/.'],
            'no trailing newline' => ['18.19.1', '18.19.1', ''],
            'windows line endings' => ["18.19.1\r\n", '18.19.1', 'A carriage return must not survive into the value.'],
            'surrounding whitespace' => ["  v18.19.1  \n", 'v18.19.1', 'Trimmed.'],
            'leading comment and blank lines' => ["# pinned for the build image\n\n  \nv20.11.0\n", 'v20.11.0', 'The first line that is neither.'],
            'trailing comment' => ["v20.11.0 # lts\n", 'v20.11.0', 'The comment is not part of the version.'],
            'only a comment' => ["# TODO decide\n", null, 'Nothing is declared by a comment.'],
            'empty' => ['', null, ''],
            'only blank lines' => ["\n\n   \n", null, ''],
            'later lines are ignored' => ["20.1.0\n22.0.0\n", '20.1.0', 'The first line decides, as it does for nvm.'],
            'a range' => [">=18 <21\n", '>=18 <21', 'Spaces and comparison operators are in the class.'],
            'a caret range' => ["^20.11\n", '^20.11', ''],
            'a tilde range' => ["~20.11.0\n", '~20.11.0', ''],
            'an or-range' => ["18 || 20\n", '18 || 20', ''],
            'an x-range' => ["20.x\n", '20.x', ''],
            'a path' => ["/usr/local/n/versions/node/20\n", null, 'A path is the one thing this must never repeat.'],
            'a url' => ["https://nodejs.org/dist/v20\n", null, ''],
            'a sentence' => ["use whatever is current; ask Dave\n", null, 'Semicolons are outside the class.'],
            'a relative path' => ["../shared/.nvmrc\n", null, ''],
            'an lts alias with a path in it' => ["lts/../../etc\n", null, 'lts/ is followed by a single segment.'],
            'one character too many' => [str_repeat('1', 33) . "\n", null, 'Longer than the schema allows.'],
            'exactly the longest' => [str_repeat('1', 32) . "\n", str_repeat('1', 32), ''],
            'non-ascii' => ["v20\u{2014}lts\n", null, ''],
        ];
    }

    public function testReadsNodeVersion(): void
    {
        $this->write('.node-version', "22.4.0\n");

        $this->assertDetects(['declared' => '22.4.0', 'source' => '.node-version']);
    }

    /**
     * @param array{declared: string|null, source: string|null} $expected
     */
    #[DataProvider('manifests')]
    public function testReadsPackageJsonEngines(string $json, array $expected, string $why): void
    {
        $this->write('package.json', $json);

        $this->assertDetects($expected, $why);
    }

    /**
     * @return array<string, array{string, array{declared: string|null, source: string|null}, string}>
     */
    public static function manifests(): array
    {
        $none = ['declared' => null, 'source' => null];

        return [
            'engines.node present' => [
                '{"name":"x","engines":{"node":">=18.17"}}',
                ['declared' => '>=18.17', 'source' => 'package.json'],
                'The range as written.',
            ],
            'surrounding whitespace' => [
                '{"engines":{"node":"  ^20  "}}',
                ['declared' => '^20', 'source' => 'package.json'],
                '',
            ],
            'engines without node' => ['{"engines":{"npm":">=9"}}', $none, 'Only node is read.'],
            'no engines' => ['{"name":"x","version":"1.0.0"}', $none, ''],
            'engines is a list' => ['{"engines":["node"]}', $none, ''],
            'engines is a string' => ['{"engines":"node 20"}', $none, ''],
            'node is a number' => ['{"engines":{"node":20}}', $none, 'Not coerced into a guess.'],
            'node is an object' => ['{"engines":{"node":{"min":"18"}}}', $none, ''],
            'node is a list' => ['{"engines":{"node":[">=18"]}}', $none, ''],
            'node is null' => ['{"engines":{"node":null}}', $none, ''],
            'node is empty' => ['{"engines":{"node":""}}', $none, ''],
            'node is a sentence' => ['{"engines":{"node":"any recent version, please"}}', $none, 'A comma is outside the class.'],
            'node is oversized' => ['{"engines":{"node":"' . str_repeat('>=18 ', 20) . '"}}', $none, 'A 100 character range is not sent in part.'],
            'node contains a path' => ['{"engines":{"node":"/opt/node/bin/node"}}', $none, ''],
            'malformed json' => ['{"engines":{"node":">=18"', $none, 'Never a half-read value.'],
            'not json at all' => ['node >= 18', $none, ''],
            'an empty file' => ['', $none, ''],
            'a json scalar' => ['"engines"', $none, ''],
            'a json list' => ['[1,2,3]', $none, ''],
        ];
    }

    public function testNvmrcBeatsNodeVersionBeatsPackageJson(): void
    {
        $this->write('package.json', '{"engines":{"node":">=16"}}');
        $this->assertDetects(['declared' => '>=16', 'source' => 'package.json']);

        $this->write('.node-version', "18.0.0\n");
        $this->assertDetects(['declared' => '18.0.0', 'source' => '.node-version']);

        $this->write('.nvmrc', "20.0.0\n");
        $this->assertDetects(['declared' => '20.0.0', 'source' => '.nvmrc']);
    }

    public function testAFileThatSaysNothingFallsThrough(): void
    {
        $this->write('.nvmrc', "# not decided yet\n");
        $this->write('.node-version', "\n");
        $this->write('package.json', '{"engines":{"node":"^20"}}');

        $this->assertDetects(['declared' => '^20', 'source' => 'package.json']);
    }

    public function testAFileThatSaysSomethingUnusableDoesNotFallThrough(): void
    {
        // The tool in use would have read .nvmrc. Reporting .node-version instead would be a
        // confident answer about a version nothing is using.
        $this->write('.nvmrc', "/opt/node\n");
        $this->write('.node-version', "20.0.0\n");

        $this->assertDetects(['declared' => null, 'source' => null]);
    }

    public function testAnOversizedFileIsNotRead(): void
    {
        $this->write('.nvmrc', "20.0.0\n" . str_repeat('#', 5000));
        $this->write('.node-version', "18.0.0\n");

        $this->assertDetects(
            ['declared' => '18.0.0', 'source' => '.node-version'],
            'A file past the cap counts as absent rather than being read in part.',
        );
    }

    public function testAnOversizedManifestIsNotRead(): void
    {
        $this->write('package.json', '{"engines":{"node":"^20"},"padding":"' . str_repeat('x', 300000) . '"}');

        $this->assertDetects(['declared' => null, 'source' => null]);
    }

    public function testASymlinkLeavingTheProjectIsIgnored(): void
    {
        $outside = sys_get_temp_dir() . '/node-declaration-outside-' . bin2hex(random_bytes(6));
        file_put_contents($outside, "99.0.0\n");

        try {
            if (!@symlink($outside, $this->root . '/.nvmrc')) {
                self::markTestSkipped('Symlinks are not available here.');
            }

            $this->write('.node-version', "20.0.0\n");

            $this->assertDetects(
                ['declared' => '20.0.0', 'source' => '.node-version'],
                'A link pointing outside the project root is not part of the project.',
            );
        } finally {
            @unlink($outside);
        }
    }

    public function testADirectoryNamedLikeAFileIsIgnored(): void
    {
        mkdir($this->root . '/.nvmrc');

        try {
            $this->assertDetects(['declared' => null, 'source' => null]);
        } finally {
            rmdir($this->root . '/.nvmrc');
        }
    }

    #[DataProvider('acceptable')]
    public function testAcceptableMatchesTheSchemaPattern(string $value, bool $expected): void
    {
        self::assertSame($expected, NodeDeclaration::acceptable($value), $value);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function acceptable(): array
    {
        return [
            'version' => ['20.11.0', true],
            'prefixed' => ['v20.11.0', true],
            'range' => ['>=18.17 <21', true],
            'alias' => ['lts/hydrogen', true],
            'empty' => ['', false],
            'trailing newline' => ["20\n", false],
            'a slash outside lts' => ['node/20', false],
            'a quote' => ['"20"', false],
            'a semicolon' => ['20; rm', false],
            'a dollar sign' => ['$NODE_VERSION', false],
            'a backslash' => ['C:\\node', false],
            'thirty two' => [str_repeat('9', 32), true],
            'thirty three' => [str_repeat('9', 33), false],
        ];
    }
}

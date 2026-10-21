<?php

/**
 * Manager Connector plugin for Craft CMS 4.x and 5.x
 *
 * @link      https://managerforcraft.com
 * @copyright Copyright (c) Coysh Digital
 */

declare(strict_types=1);

namespace coyshdigital\managerconnector\services;

/**
 * The Node.js version a project says it needs. Declared, never observed.
 *
 * Nothing here runs `node`, and nothing here could: the question "what is installed on this machine"
 * would need a shell, and this plugin cannot shell out. What it can read is the file a developer
 * already wrote to say which version the project wants - `.nvmrc`, `.node-version`, or `engines.node`
 * in `package.json` - and that is what the report carries. It is also, usefully, the version the
 * *build* wants, which is the one that decides whether a deploy works.
 *
 * Pure on purpose. It takes a directory and returns two values, with no Craft application behind it,
 * so the cases that matter - a comment line, a range, a value that is really a sentence - can be
 * tested without booting one.
 *
 * The rules, since each exists because the alternative leaks something:
 *
 *  - **Only the project root, only these three names.** A path is never built from anything but a
 *    constant, and a file that resolves outside the root (a symlink pointing at somebody's home
 *    directory) is treated as absent.
 *  - **The first file that declares anything decides.** `.nvmrc` first, because it is what `nvm`
 *    itself reads. If that value does not fit the protocol's pattern, the answer is "nothing
 *    declared" and not the next file's - reporting `.node-version` when the tool in use would have
 *    read `.nvmrc` is a confident wrong answer.
 *  - **The string as written, or nothing.** Never trimmed down to something that fits. A partial
 *    version is a different version.
 *  - **Bounded.** A file larger than the cap is not read and counts as absent, and a value longer
 *    than the schema allows is not sent.
 */
final class NodeDeclaration
{
    /**
     * Longest value `system.v4` allows. Repeated here because a value that fails the schema would get
     * the whole runtime report refused, and a runtime report is fire-and-forget.
     */
    public const MAX_LENGTH = 32;

    /**
     * `.nvmrc` and `.node-version` are one line. A kilobyte is generous.
     */
    private const VERSION_FILE_BYTES = 1024;

    /**
     * A `package.json` is the one that can be large. Past this it is not read at all: half a JSON
     * document does not decode, and a manifest this big is not one anybody put `engines` in by hand.
     */
    private const MANIFEST_BYTES = 262144;

    /**
     * Same character set as `system.v4`'s `node.declared`. Digits, letters and the punctuation a
     * range uses; the only slash is the one in an `lts/` alias.
     */
    private const PATTERN = '/^(lts\/[A-Za-z0-9*.-]+|[A-Za-z0-9.+^~<>=*| -]+)$/D';

    /**
     * @return array{declared: string|null, source: string|null}
     */
    public static function detect(string $root): array
    {
        $none = ['declared' => null, 'source' => null];

        $root = self::realRoot($root);

        if ($root === null) {
            return $none;
        }

        foreach (['.nvmrc', '.node-version', 'package.json'] as $name) {
            $content = self::read($root, $name);

            if ($content === null) {
                continue;
            }

            $raw = $name === 'package.json' ? self::fromManifest($content) : self::fromVersionFile($content);

            // Nothing written in this file: carry on to the next one.
            if ($raw === null) {
                continue;
            }

            return self::acceptable($raw)
                ? ['declared' => $raw, 'source' => $name]
                : $none;
        }

        return $none;
    }

    /**
     * The first line that is not blank and not a comment, with any trailing comment removed.
     *
     * `nvm` takes the first line and ignores the rest; tools that tolerate comments take the first
     * line that is not one. Both give the same answer for every file anybody writes by hand.
     */
    public static function fromVersionFile(string $content): ?string
    {
        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $hash = strpos($line, '#');

            if ($hash !== false) {
                $line = rtrim(substr($line, 0, $hash));
            }

            return $line === '' ? null : $line;
        }

        return null;
    }

    /**
     * `engines.node`, when it is there and is a string.
     *
     * An array, a number or an object is not a version somebody wrote down, so it is "nothing
     * declared" and not a coerced guess.
     */
    public static function fromManifest(string $json): ?string
    {
        $decoded = json_decode($json, true);

        if (!is_array($decoded) || !is_array($decoded['engines'] ?? null)) {
            return null;
        }

        $node = $decoded['engines']['node'] ?? null;

        if (!is_string($node)) {
            return null;
        }

        $node = trim($node);

        return $node === '' ? null : $node;
    }

    /**
     * Whether a value fits what `system.v4` will accept.
     */
    public static function acceptable(string $value): bool
    {
        return $value !== ''
            && strlen($value) <= self::MAX_LENGTH
            && preg_match(self::PATTERN, $value) === 1;
    }

    private static function realRoot(string $root): ?string
    {
        if ($root === '') {
            return null;
        }

        $real = realpath($root);

        return $real !== false && is_dir($real) ? $real : null;
    }

    /**
     * One file's contents, or null for absent, unreadable, oversized or outside the root.
     */
    private static function read(string $root, string $name): ?string
    {
        $path = $root . DIRECTORY_SEPARATOR . $name;

        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        // A link inside the project that points somewhere else on the machine is not part of the
        // project. realpath() resolves it, and the prefix check is what refuses it.
        $resolved = realpath($path);

        if ($resolved === false || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }

        $cap = $name === 'package.json' ? self::MANIFEST_BYTES : self::VERSION_FILE_BYTES;
        $size = @filesize($resolved);

        if ($size === false || $size > $cap) {
            return null;
        }

        $content = @file_get_contents($resolved, false, null, 0, $cap);

        return $content === false ? null : $content;
    }
}

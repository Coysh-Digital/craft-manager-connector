#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Structural checks on the connector's own source.
 *
 * Invariants 4 and 5 from the specification:
 *
 *   4. The connector must not expose a public inbound management endpoint.
 *   5. Connections must be initiated outbound by the connector.
 *
 * Those are properties of what this plugin registers, so they are checked by reading the source
 * rather than by booting Craft. That keeps this runnable in CI in a second, with no dependencies and
 * no Craft install — which means it actually gets run.
 *
 * Deliberately conservative: it errs towards flagging something for a human to look at. A check that
 * only fires on a perfect match would miss the interesting cases.
 *
 * Usage:  php bin/verify-invariants.php
 */

$root = dirname(__DIR__);
$sourceDir = $root.'/src';

$failures = [];
$checks = 0;

/**
 * @return list<string>
 */
function phpFilesIn(string $directory): array
{
    $files = [];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

$sources = phpFilesIn($sourceDir);

// --------------------------------------------------------------------------------------------------
// Invariants 4 and 5: nothing inbound.
// --------------------------------------------------------------------------------------------------
//
// Registering a URL rule would give the outside world a way to reach this plugin. Everything it does
// is started from here, outbound, which is what lets a site behind NAT report with no inbound
// firewall rule at all.
$checks++;

foreach ($sources as $file) {
    $contents = (string) file_get_contents($file);
    $relative = str_replace($root.'/', '', $file);

    foreach ([
        'RegisterUrlRulesEvent' => 'registers URL rules, which would expose an inbound endpoint',
        'EVENT_REGISTER_SITE_URL_RULES' => 'registers site URL rules',
        'EVENT_REGISTER_CP_URL_RULES' => 'registers control-panel URL rules',
    ] as $needle => $why) {
        if (str_contains($contents, $needle)) {
            $failures[] = "{$relative} {$why} (invariants 4 and 5).";
        }
    }
}

// A web controller would be reachable over HTTP even without an explicit route, through Craft's
// default action routing.
$checks++;

foreach ($sources as $file) {
    $relative = str_replace($root.'/', '', $file);

    if (str_contains($relative, 'src/controllers/')) {
        $failures[] = "{$relative} is a web controller. Only console controllers are permitted (invariants 4 and 5).";
    }
}

// --------------------------------------------------------------------------------------------------
// Invariant 8: no arbitrary execution.
// --------------------------------------------------------------------------------------------------
//
// No capability may provide arbitrary PHP, shell, console or SQL execution. The connector must not
// contain the means even if something else asked it to.
$checks++;

$forbidden = [
    'eval(' => 'evaluates PHP',
    'exec(' => 'executes a shell command',
    'shell_exec' => 'executes a shell command',
    'passthru' => 'executes a shell command',
    'proc_open' => 'opens a process',
    'popen(' => 'opens a process',
    'system(' => 'executes a shell command',
    'assert(' => 'can evaluate a string as code',
    'create_function' => 'creates code at runtime',
];

foreach ($sources as $file) {
    $contents = (string) file_get_contents($file);
    $relative = str_replace($root.'/', '', $file);

    foreach ($forbidden as $needle => $why) {
        if (str_contains($contents, $needle)) {
            $failures[] = "{$relative} {$why}: '{$needle}' (invariant 8).";
        }
    }
}

// --------------------------------------------------------------------------------------------------
// Dependencies stay conservative.
// --------------------------------------------------------------------------------------------------
//
// The specification asks for new connector dependencies to be reviewed more strictly than ordinary
// dashboard ones. This is the gate: adding a runtime requirement has to be a deliberate edit here
// as well.
$checks++;

$manifest = json_decode((string) file_get_contents($root.'/composer.json'), true);

$permitted = ['php', 'ext-sodium', 'craftcms/cms', 'coysh-digital/manager-protocol'];
$declared = array_keys($manifest['require'] ?? []);
$unexpected = array_diff($declared, $permitted);

if ($unexpected !== []) {
    $failures[] = 'composer.json requires packages not on the reviewed list: '
        .implode(', ', $unexpected)
        .'. Add it here once the dependency has been reviewed.';
}

// --------------------------------------------------------------------------------------------------
// The private key never leaves the installation.
// --------------------------------------------------------------------------------------------------
$checks++;

$client = (string) file_get_contents($sourceDir.'/services/Client.php');

// The pairing request sends the public half only. If the secret key ever appeared in a payload, the
// platform would hold something that could impersonate this site.
if (preg_match('/[\'"]secret_key[\'"]\s*=>/', $client) === 1) {
    $failures[] = 'src/services/Client.php appears to transmit a secret key (invariant 11).';
}

if (! str_contains($client, "'public_key' => \$keypair['public']")) {
    $failures[] = 'src/services/Client.php no longer sends the public key during pairing; check what it sends instead.';
}

// --------------------------------------------------------------------------------------------------
// Report
// --------------------------------------------------------------------------------------------------

$scanned = count($sources);

if ($failures !== []) {
    fwrite(STDERR, "\nConnector invariant checks FAILED\n\n");

    foreach ($failures as $failure) {
        fwrite(STDERR, "  ✕ {$failure}\n");
    }

    fwrite(STDERR, "\n{$scanned} files scanned, {$checks} checks run.\n\n");

    exit(1);
}

fwrite(STDOUT, "\n  ✓ Connector invariants hold ({$checks} checks over {$scanned} files).\n\n");

exit(0);

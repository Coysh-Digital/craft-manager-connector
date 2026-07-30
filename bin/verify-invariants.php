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
 * A file's code with its comments removed.
 *
 * Every "does this source contain X" check below runs against this rather than the raw file. Scanning
 * raw text means a docblock explaining why the connector does not shell out reads, to the checker,
 * exactly like shelling out — and a check that prose can trip is a check people learn to phrase around
 * instead of a check that holds.
 */
function sourceWithoutComments(string $path): string
{
    $code = '';

    foreach (token_get_all((string) file_get_contents($path)) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    return $code;
}

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
    $contents = sourceWithoutComments($file);
    $relative = str_replace($root.'/', '', $file);

    foreach ($forbidden as $needle => $why) {
        if (str_contains($contents, $needle)) {
            $failures[] = "{$relative} {$why}: '{$needle}' (invariant 8).";
        }
    }
}

// --------------------------------------------------------------------------------------------------
// Invariant 9, from the connector's side: it refuses job types it does not implement.
// --------------------------------------------------------------------------------------------------
//
// The platform refuses to *issue* an unknown job type; this refuses to *execute* one. Two independent
// refusals, because they protect against different failures — the platform's stops a mistake, the
// connector's stops a compromised or impersonated platform.
$checks++;

$runner = sourceWithoutComments($sourceDir.'/services/JobRunner.php');

if (! str_contains($runner, 'function canHandle(')) {
    $failures[] = 'src/services/JobRunner.php no longer gates job types through canHandle() (invariant 9).';
}

if (! str_contains($runner, 'if (! $this->canHandle($type))')) {
    $failures[] = 'src/services/JobRunner.php no longer refuses unrecognised job types before running them (invariant 9).';
}

// Dispatch must not be derived from the payload. A method name built from a job type, or a callable
// resolved from a string, would turn the job type into a way to reach arbitrary code — which is
// invariant 8 by the back door.
foreach (['call_user_func', 'call_user_func_array', '$this->$', '->{$', 'ReflectionMethod', 'invokeArgs'] as $dynamic) {
    if (str_contains($runner, $dynamic)) {
        $failures[] = "src/services/JobRunner.php dispatches dynamically via '{$dynamic}'; use a match over known constants (invariants 8 and 9).";
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

$client = sourceWithoutComments($sourceDir.'/services/Client.php');

// The pairing request sends the public half only. If the secret key ever appeared in a payload, the
// platform would hold something that could impersonate this site.
if (preg_match('/[\'"]secret_key[\'"]\s*=>/', $client) === 1) {
    $failures[] = 'src/services/Client.php appears to transmit a secret key (invariant 11).';
}

if (! str_contains($client, "'public_key' => \$keypair['public']")) {
    $failures[] = 'src/services/Client.php no longer sends the public key during pairing; check what it sends instead.';
}

// --------------------------------------------------------------------------------------------------
// A backup can only ever be sent to the platform this site paired with.
// --------------------------------------------------------------------------------------------------
//
// The most valuable thing an attacker could do with a compromised platform is ask every managed site
// for its database and have it delivered somewhere else. That is prevented structurally rather than by
// validation: the upload destination is read from the stored connection record, and there is no
// argument, parameter or payload field anywhere in the path that could replace it.
//
// So this check is about the *absence* of a seam. If somebody later adds a destination argument for
// convenience, this fails and they have to come and read this comment.
$checks++;

$backupRunner = sourceWithoutComments($sourceDir.'/services/BackupRunner.php');

if (! str_contains($client, '$record->platformUrl')) {
    $failures[] = 'src/services/Client.php no longer sends uploads to the paired platform URL; check where they go instead (invariants 4, 5 and 8).';
}

// putFile takes a path on this server and a hash, and nothing that names a destination host.
if (preg_match('/function putFile\((.*?)\)/s', $client, $signature) !== 1) {
    $failures[] = 'src/services/Client.php no longer defines putFile(); the artifact upload path has changed.';
} else {
    foreach (['url', 'host', 'endpoint', 'destination', 'bucket'] as $forbidden) {
        if (stripos($signature[1], $forbidden) !== false) {
            $failures[] = "src/services/Client.php putFile() accepts a '{$forbidden}' argument; an artifact destination must never be a parameter (invariant 8).";
        }
    }
}

// The runner must not read a destination out of the job either.
foreach (['$parameters[\'url', '$parameters[\'endpoint', '$job[\'url', '$declared[\'url'] as $forbidden) {
    if (str_contains($backupRunner, $forbidden)) {
        $failures[] = 'src/services/BackupRunner.php reads a destination from a job payload (invariant 8).';
    }
}

// No shell. Craft's own backup uses the site's existing connection; composing a dump command here
// would be arbitrary execution wearing a backup's clothes.
foreach (['exec(', 'shell_exec', 'passthru', 'proc_open', 'popen', 'mysqldump', 'pg_dump'] as $forbidden) {
    if (str_contains($backupRunner, $forbidden)) {
        $failures[] = "src/services/BackupRunner.php contains '{$forbidden}'; the dump must go through Craft's own backup (invariant 8).";
    }
}

// An unencrypted artifact must never be uploaded, so a missing platform key has to be fatal.
if (! str_contains($backupRunner, 'the platform has no artifact encryption key configured')) {
    $failures[] = 'src/services/BackupRunner.php no longer refuses to proceed without an encryption key; a backup must never travel unencrypted.';
}

// The plaintext dump and the encrypted artifact must both be removed whatever happened, which means
// inside a finally rather than at the end of the happy path. Matched as a shape instead of merely
// checking that shred() is called somewhere: it is also called on the early-return paths, so a looser
// check would pass with the finally block deleted entirely.
if (preg_match('~finally\s*\{.{0,400}?shred\(\$dump.{0,300}?shred\(\$encrypted~s', $backupRunner) !== 1) {
    $failures[] = 'src/services/BackupRunner.php no longer removes both the dump and the artifact in a finally block; a failed backup must not leave a copy of the database on disk.';
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

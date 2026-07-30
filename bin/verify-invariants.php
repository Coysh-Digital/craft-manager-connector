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
$templateDir = $sourceDir.'/templates';

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
// Invariants 4 and 5: nothing the outside world can reach, and nothing the platform can call.
// --------------------------------------------------------------------------------------------------
//
// Invariant 4 forbids a **public** inbound management endpoint. It does not forbid a control-panel form
// behind an authenticated administrator, and the distinction matters in practice: plenty of Craft sites
// run on managed hosting with no shell, and requiring SSH to pair would exclude them rather than
// inconvenience them.
//
// So this no longer checks that no controller exists. It checks the properties that make the one that
// does exist safe, which is a harder thing to satisfy by accident:
//
//   - no site routes at all, ever — those are genuinely public
//   - control-panel routes limited to an allowlist written here
//   - every web controller refuses anonymous access and requires an administrator
//   - every state-changing action requires POST, so Craft's CSRF token is enforced
//   - no web controller reads a job, a command or a capability from the request
//
// The last one is the line that keeps invariant 5 true. A form here causes this site to call the
// platform; nothing lets the platform call in, and nothing web-reachable accepts an instruction.
$checks++;

foreach ($sources as $file) {
    $contents = sourceWithoutComments($file);
    $relative = str_replace($root.'/', '', $file);

    // Site routes are public by definition. No exceptions, no allowlist.
    if (str_contains($contents, 'EVENT_REGISTER_SITE_URL_RULES')) {
        $failures[] = "{$relative} registers site URL rules, which are publicly reachable (invariant 4).";
    }
}

// The control-panel routes this plugin may register. Adding one is an edit here as well as there.
// Empty, and it should stay that way. The screen is a Craft utility, which Craft routes, and the two
// state-changing actions go through Craft's action mechanism — POST and a CSRF token, no URL rule.
// Nothing this plugin registers can be reached by following a link.
$permittedCpRoutes = [];

$pluginSourceForRoutes = sourceWithoutComments($sourceDir.'/Plugin.php');

if (preg_match_all("/\\\$event->rules\\['([^']+)'\\]/", $pluginSourceForRoutes, $registered) === false) {
    $failures[] = 'src/Plugin.php URL rules could not be read; check what it registers.';
} else {
    foreach ($registered[1] as $route) {
        if (! in_array($route, $permittedCpRoutes, true)) {
            $failures[] = "src/Plugin.php registers the control-panel route '{$route}', which is not on the "
                .'reviewed list in this script (invariant 4).';
        }
    }
}

// --------------------------------------------------------------------------------------------------
// Every web controller is administrator-only, POST-only, and deaf to the platform.
// --------------------------------------------------------------------------------------------------
$checks++;

foreach ($sources as $file) {
    $relative = str_replace($root.'/', '', $file);

    if (! str_contains($relative, 'src/controllers/')) {
        continue;
    }

    $contents = sourceWithoutComments($file);

    if (! preg_match('/\\$allowAnonymous\s*=\s*false/', $contents)) {
        $failures[] = "{$relative} does not declare \$allowAnonymous = false (invariant 4).";
    }

    // Either an administrator or the utility's own permission. Not merely "some permission": the exact
    // string is asserted so this cannot quietly become a weaker one that every editor holds.
    // Matched with tolerance for spacing and string-concatenation style, because a formatter must
    // never be able to disable a security check. `craftcms/ecs` is an unpinned dev-main dependency and
    // a change in its rules has already turned `! $x` into `!$x` across this repository once; a check
    // that went quiet on that would be worse than no check, because it would still report success.
    if (! preg_match('~\$this->requireAdmin\(~', $contents)
        && ! preg_match("~requirePermission\(\s*'utility:'\s*\.\s*ConnectorUtility::id\(\)\s*\)~", $contents)) {
        $failures[] = "{$relative} does not require an administrator or the utility permission (invariant 4).";
    }

    // Every action that changes something has to be POST, or Craft will not enforce CSRF and the route
    // becomes something a crafted link can trigger.
    //
    // Actions that only render are exempt, but by name rather than by inspection: adding a read-only
    // action means adding it here, which is a moment to consider whether it really is one.
    $readOnlyActions = [];

    $actions = preg_match_all('/public function action(\w+)\(/', $contents, $found) ? $found[1] : [];
    $changing = array_values(array_diff($actions, $readOnlyActions));
    $postRequirements = preg_match_all('/requirePostRequest\(\)/', $contents);

    if ($changing !== [] && $postRequirements < count($changing)) {
        $failures[] = "{$relative} has ".count($changing).' state-changing '
            .(count($changing) === 1 ? 'action' : 'actions').' but only '.$postRequirements
            .' requirePostRequest() calls. Without POST, Craft does not enforce CSRF and the route is '
            .'reachable from a link (invariant 4).';
    }

    // Nothing web-reachable may take direction from the platform. A controller reading any of these
    // from the request would be an inbound control channel wearing a form's clothes.
    foreach (['jobs', 'capabilities', 'command', 'job_id', 'artifact'] as $forbidden) {
        if (preg_match('/getBodyParam\([\'"]'.$forbidden.'/i', $contents) === 1
            || preg_match('/getQueryParam\([\'"]'.$forbidden.'/i', $contents) === 1) {
            $failures[] = "{$relative} reads '{$forbidden}' from the request. Web routes must not accept "
                .'platform instructions (invariants 5 and 8).';
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

if (! preg_match('~if\s*\(\s*!\s*\$this->canHandle\(\$type\)\s*\)~', $runner)) {
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
// The platform does not choose who can read a backup.
// --------------------------------------------------------------------------------------------------
//
// This is the counterpart to the destination check above, and the same argument.
//
// A backup is encrypted to keys the platform names, because the site has to be told which keys the
// organisation holds and the platform is what tells it. That makes recipient selection an instruction
// from an untrusted party, exactly like a destination would be. A Manager installation that had been
// compromised, or compelled, could name a key of its own — and unlike a redirected upload there would
// be no symptom at all. The backup would succeed. It would simply be readable by somebody else.
//
// So the same shape of defence: the answer comes from a file on this server rather than from the wire.
// `config/manager-connector.php` lists the fingerprints this site accepts, the connector recomputes
// each offered key's fingerprint from its key material, and anything unpinned fails the whole backup.
//
// These checks exist because every one of those properties is easy to weaken by accident while making
// something else work, and none of them fails visibly when it is gone.
$checks++;

$recipientPinPath = $sourceDir.'/services/RecipientPin.php';

if (!is_file($recipientPinPath)) {
    $failures[] = 'src/services/RecipientPin.php is missing; nothing checks which keys the platform is allowed to name (invariant 8).';
} else {
    $recipientPin = sourceWithoutComments($recipientPinPath);

    // The pinned list must come from local settings, not from anything that arrived over the wire.
    //
    // Matched on the receiver as well as the property name. Checking for the bare name would pass on
    // any mention of it anywhere in the file — including the unrelated one in isConfigured() — so a
    // version that read the list out of the platform's response would still have satisfied it. That
    // is not a hypothetical: it is what the first draft of this check did.
    if (!preg_match('~\$settings->recoveryKeyFingerprints~', $recipientPin)) {
        $failures[] = 'src/services/RecipientPin.php no longer reads recoveryKeyFingerprints from local settings; the platform could then choose who can read this site\'s backups (invariant 8).';
    }

    // The fingerprint must be recomputed from the key material. Comparing the fingerprint the platform
    // *sent* would let it label its own key with a customer's fingerprint and pass the check while
    // sealing to the wrong key.
    if (!str_contains($recipientPin, 'KeyFingerprint::forRecoveryKey(')) {
        $failures[] = 'src/services/RecipientPin.php no longer derives fingerprints from key material; a platform could then label its own key with a fingerprint this site trusts (invariant 8).';
    }

    // An unpinned key must fail the backup rather than being filtered out of it. A `continue` here
    // would produce a working backup and hide the fact that an unauthorised key was offered.
    if (!preg_match('~isPinned\(.{0,400}?throw new RuntimeException~s', $recipientPin)) {
        $failures[] = 'src/services/RecipientPin.php no longer refuses outright when an unpinned key is offered; filtering the offer would hide it (invariant 8).';
    }
}

// The pin must be consulted before the database is dumped. A refusal that happens afterwards has
// already written a complete copy of the database to disk, which is the file this whole feature is
// arranged around not creating unnecessarily.
//
// Compared by position rather than matched as a pattern, and that is the second attempt. The obvious
// regex — accept(...) followed by takeDump( — passes on a file where the order has been reversed,
// because `takeDump(` also appears further down as the method's own declaration. A check that reports
// success on the exact mutation it exists to catch is worse than no check.
$acceptAt = strpos($backupRunner, 'recipientPin->accept(');
$dumpAt = strpos($backupRunner, '$this->takeDump(');

if ($acceptAt === false) {
    $failures[] = 'src/services/BackupRunner.php no longer consults RecipientPin before backing up (invariant 8).';
} elseif ($dumpAt !== false && $acceptAt > $dumpAt) {
    $failures[] = 'src/services/BackupRunner.php checks the offered recovery keys after taking the dump; a refused backup must never leave a plaintext database on disk (invariant 8).';
}

// Nothing may seal an artifact key to a recipient that did not come back from accept().
if (preg_match('~accept\(\$offeredRecipients\)~', $backupRunner) !== 1) {
    $failures[] = 'src/services/BackupRunner.php seals to recipients that have not been through RecipientPin::accept() (invariant 8).';
}

// The format floor is a ratchet. A method that lowered it would undo the only downgrade control that
// survives the platform being the adversary.
$connectionSource = sourceWithoutComments($sourceDir.'/services/Connection.php');

if (!str_contains($connectionSource, 'raiseBackupFormatFloor')) {
    $failures[] = 'src/services/Connection.php no longer raises a backup format floor; a compromised platform could then ask this site to go back to backups it can read (invariant 8).';
}

foreach (['lowerBackupFormatFloor', 'resetBackupFormatFloor', 'clearBackupFormatFloor'] as $forbidden) {
    if (str_contains($connectionSource, $forbidden)) {
        $failures[] = "src/services/Connection.php defines {$forbidden}(); the format floor must only ever go up (invariant 8).";
    }
}

// The upload host is built from local configuration and never from platform-supplied data.
//
// Checked as a shape, because the danger is a string concatenation that looks harmless. Only one
// variable may follow a scheme literal, and its provenance is pinned separately below — matching on
// the name alone would pass on any local called $configuredHost, including one assigned from a
// response body.
foreach (['services/Client.php', 'services/BackupRunner.php'] as $relative) {
    $source = sourceWithoutComments($sourceDir.'/'.$relative);

    if (preg_match('~[\'"]https?://[\'"]\s*\.\s*\$(?!configuredHost)~', $source) === 1) {
        $failures[] = "src/{$relative} builds a URL from a variable other than the configured upload host; a destination must never come from the platform (invariant 8).";
    }

    if (str_contains($source, '$configuredHost')
        && ! str_contains($source, '$configuredHost = trim($settings->backupUploadHost);')) {
        $failures[] = "src/{$relative} uses \$configuredHost without assigning it from config/manager-connector.php (invariant 8).";
    }
}

// And none of the three settings that carry these decisions may be editable from the control panel. A
// hijacked control-panel session that could re-pin this site would defeat the only control that works.
$settingsTemplate = is_file($templateDir.'/settings.twig')
    ? (string) file_get_contents($templateDir.'/settings.twig')
    : '';

foreach (['recoveryKeyFingerprints', 'requirePinnedRecoveryKeys', 'backupUploadHost'] as $configOnly) {
    if (str_contains($settingsTemplate, $configOnly)) {
        $failures[] = "src/templates/settings.twig exposes {$configOnly}; it must be config-file only, or a hijacked control-panel session could change who can read this site's backups (invariant 8).";
    }
}

// --------------------------------------------------------------------------------------------------
// The declared version agrees with itself.
// --------------------------------------------------------------------------------------------------
//
// Invariant 17 asks for verifiable release artifacts, and a release whose version is ambiguous is not
// one. This connector states its version in two places — the constant it signs into every request, and
// composer.json — and a release adds a third in the git tag.
//
// This check exists because those drifted: the constant went to 1.2.0 and composer.json stayed at
// 1.1.0, so Packagist saw a tag that disagreed with the manifest and published nothing at all. The
// failure was silent from here and only visible as a package with no versions.
$checks++;

$pluginSource = sourceWithoutComments($sourceDir.'/Plugin.php');

if (preg_match("/const VERSION = '([^']+)'/", $pluginSource, $constant) !== 1) {
    $failures[] = 'src/Plugin.php no longer declares a VERSION constant.';
} else {
    $declared = $manifest['version'] ?? null;

    if ($declared === null) {
        $failures[] = 'composer.json has no version field, but Plugin::VERSION is '.$constant[1]
            .'. Either both state the version or neither does.';
    } elseif ($declared !== $constant[1]) {
        $failures[] = "composer.json says version {$declared} but Plugin::VERSION is {$constant[1]}. "
            .'A release with two different version numbers is not a verifiable one (invariant 17).';
    }
}

// --------------------------------------------------------------------------------------------------
// The platform is only ever contacted over TLS.
// --------------------------------------------------------------------------------------------------
//
// The specification says TLS remains mandatory and that signing does not replace it. Those are
// different properties and it is worth being precise about which: a signature makes a request
// tamper-evident, it does not make it unreadable. Over plain HTTP the enrolment code — a bearer secret
// until it is consumed — is visible to anything on the path, and so is every inventory report after it.
//
// Nothing enforced this until it was checked here: the settings rule supplied https as a *default*
// scheme, which quietly accepts an explicit http://.
$checks++;

if (! str_contains($client, 'function requireSecureUrl(')) {
    $failures[] = 'src/services/Client.php no longer refuses a non-HTTPS platform URL. TLS is mandatory and signing does not replace it.';
} else {
    // Every outbound path has to go through it, including the ones that read a stored URL rather than
    // an argument — a record written by an older build must not be a way round this.
    $callSites = preg_match_all('/requireSecureUrl\(/', $client);

    if ($callSites < 4) {
        $failures[] = 'src/services/Client.php defines requireSecureUrl() but does not call it on every outbound path ('
            .$callSites.' occurrences including the declaration; expected at least 4).';
    }
}

if (! str_contains(sourceWithoutComments($sourceDir.'/models/Settings.php'), "'validSchemes' => ['https']")) {
    $failures[] = 'src/models/Settings.php no longer restricts platformUrl to https.';
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

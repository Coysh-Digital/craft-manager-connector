# Changelog

## 1.10.2

The backups panel no longer claims backups are blocked when they are not.

### A correctly configured site was told its backups would not run

The panel read the backup format floor, and that floor is a ratchet raised only *after* a v2 backup
succeeds. So every site reads `v1` until its first one lands — including an organisation using
recovery keys, whose platform has therefore never published an artifact key of its own, because
under v2 it does not need one.

The screen then stated, flatly, that no backup would be taken. Two live sites were told that while
the platform was asking them for v2 and the real fault was elsewhere. That is worse than saying
nothing: it sends somebody to fix a configuration that is already correct.

It now describes what is known rather than predicting what will happen — this site cannot tell which
format it will be asked for until it is asked — and still says plainly that an organisation with no
recovery key and no platform key cannot have a backup taken.

## 1.10.1

A refused backup now says why.

### The platform's reason was arriving and being thrown away

A rejection body carries a `reason`, and `Client` reported only the status and a correlation
identifier — decoding the body to find the identifier and discarding the explanation sitting beside
it. So a failed backup read as `The platform rejected the request (HTTP 422)` and pointed at an id
which, on the platform side, was not always written down. The explanation had already arrived in the
same response.

Both rejection paths now include it, so the message names the cause: a quota, an unrecognised
format, a limit.

Safe to print, and only because of what these strings are: fixed messages the platform composes
about its own refusal, never anything a site reported about its own contents.

## 1.10.0

A platform that stores and meters the artifact may now lift this site's backup size limit.

### `maxBackupMegabytes` is self-hosted only

`backup.create` may carry `max_megabytes`, and zero means no limit. When it is absent — which is
every self-hosted Manager — the site's own `maxBackupMegabytes` stands exactly as before, and
nothing about this release changes what those installations do.

The setting bounds a dump written to this site's disk, and self-hosted that is the right control in
the right place: whoever sets it owns the disk. Reporting to Manager Cloud it is neither. The
storage is the platform's, already metered and billed, and this config file lives on your own
server — most sites have no config file at all, so a 2 GB default nobody chose was refusing backups
of space already being paid for.

**Only ever a size.** The destination and the recipient list are unchanged and still come from local
configuration alone. A size can only permit less work than this site would already do, or lift a
limit on a platform that has taken responsibility for holding the result; it cannot redirect a
backup or widen who is able to open one. `bin/verify-invariants.php` is untouched and still fails
the build on any url, endpoint or destination read from job parameters.

`docs/backups.md` said the job "carries no parameters at all". That is no longer true, and it is the
page explaining what the platform can and cannot influence, so it now states what may be carried and
why a size is a different kind of thing from a destination.

## 1.9.1

A fresh install could not read `backupFormatFloor`, and the plugin now points at managerforcraft.com.

### The connection table was missing a column on fresh installs

`Getting unknown property: ...ConnectionRecord::backupFormatFloor`, raised on the backup path where
`BackupRunner` asks for the floor before choosing a format.

The column arrived in 1.7.0 with `m260805_090000_add_backup_format_floor`, but it was never added to
`Install`. Craft installs a plugin by running the install migration and then marking every other
migration as applied **without running it** (`craft\base\Plugin::install()`), so the two populations
diverged: a site that *upgraded* into 1.7.0 ran the migration and has the column, and a site that
*installed* 1.7.0 or later from scratch has the migration recorded as done and no column at all.

That is 1.7.0, 1.7.1, 1.8.0 and 1.9.0 — every release since the ratchet was introduced. If your sites
were upgraded rather than freshly installed, none of them were affected.

`Install` now creates the column, which fixes the next fresh install but not the ones already out
there, whose migration history says the work is finished. So there is also a new migration,
`m260731_210000_backfill_backup_format_floor`, which adds the column where it is missing. Editing the
1.7.0 migration would have done nothing on exactly the sites that need it.

Both migrations are idempotent and additive, and `v1` is the correct starting value: a site that had
genuinely taken v2 backups already had the column. `schemaVersion` moves to 1.3.0 so Craft runs the
repair on update.

### Manager for Craft, not coysh.digital

The `@link` header on all 33 source files, the Composer author homepage and the plugin's
`developerUrl` now point at managerforcraft.com. The security contact stays `hello@coysh.digital` —
that is a reporting address rather than a link, and moving it would break the path this plugin asks
people to use.

### Pairing defaults to Manager Cloud

The platform field on the pairing screen starts at `https://console.managerforcraft.com`, which is
where most sites pair. It is a starting value and not a setting: the field is still editable, and a
self-hosted installation replaces it. Setting `platformUrl` in `config/manager-connector.php` still
locks the field, as before.

## 1.9.0

Craft 4 is supported. Nothing was removed to get there, and no capability behaves differently on one
major than the other.

Requires `craftcms/cms: ^4.4 || ^5.0`, `php: >=8.1` and `manager-protocol: ^1.4`.

### Why this is a small change

The connector barely touches Craft. Its whole surface is twenty imported classes, about twenty-eight
`Craft::$app` call sites, one Twig template and one table — and it reads no elements at all, so
Craft 5's entrification and the dropped `content` table never mattered to it. Every one of those
classes exists in Craft 4 under the same name.

Three things were already version-tolerant and needed nothing: `Reporter::queue()` already
`instanceof`-guards `craft\queue\Queue`, `SystemReporter::volumePath()` already reaches `getSubpath`
through `method_exists` (Craft 4.0 to 4.3 has no volume subpath and one volume is one filesystem),
and `editionHandle()` already read the edition through enum, object and int shapes.

### The one that would have shipped broken

Craft renamed the utility registration event: `EVENT_REGISTER_UTILITY_TYPES` on 4,
`EVENT_REGISTER_UTILITIES` on 5. Same event class, same `$event->types[]` contract, different
constant.

Getting this wrong raises nothing. Yii attaches the listener to an event nothing dispatches, the
utility never registers, and on Craft 4 that means **no pairing screen at all** — a plugin that
installs cleanly, reports nothing, and offers no visible reason why. `Plugin::registerUtilitiesEvent()`
now resolves it by constant rather than by Craft version, so it stays correct if either major
backports the other's name.

This was found by running PHPStan against Craft 4's real signatures, not by reading the source. It
had already survived a careful read of the whole Craft surface, which is the argument for the CI
matrix below.

### The rest

- `ConnectorUtility` declares both `icon()` and `iconPath()`. Craft 5 takes an icon name, Craft 4
  takes a path to an SVG; each major calls the one it knows and ignores the other. The SVG is drawn
  in this repository rather than lifted from an icon set, because Font Awesome's free tier is CC BY
  4.0 and the attribution requirement has no sensible home inside a Craft utility.
- `editionHandle()` no longer reports a Pro site as `team` on Craft 4.0 to 4.4. Craft added the Team
  edition in 4.5 and inserted it at 1, moving Pro from 1 to 2 — so on older 4.x the same integer
  means Pro. Wrong quietly, in a field an operator would have no reason to distrust.

### What holds it

CI now analyses both majors: Craft 4.4 on PHP 8.1, Craft 5.0 on PHP 8.4. That is the job that caught
the event rename, and it is the reason to keep paying for it — both differences between the majors
are silent at runtime, so neither would show up as a failing build without it.

The advisories step runs on the Craft 5 leg only. Craft 4 pins `twig/twig ~3.19.0`, which carries
published advisories with no fix inside that constraint, so the step would be permanently red there
through no fault of this plugin — and a check that is always red carries no information. **This is
worth knowing before adopting Craft 4 support:** it is a real fact about Craft 4 that Manager will
report as a finding on any site running it, and nothing in this repository can fix it.

### PHP 8.1, not 8.0.2

Craft 4 itself runs on 8.0.2+, so this does not reach every Craft 4 site. Going lower meant removing
`readonly` from thirteen promoted properties in `manager-protocol` — on `CanonicalRequest`,
`CanonicalResponse` and `SchemaValidator`, which are the objects a signature is computed over.
Trading their immutability for the sites still on 8.0 was judged not worth it.

## 1.8.0

Volumes are measured where their files actually are, and update reports say what changed.

### Every volume reported the same size

A Craft 5 volume is a filesystem *and a subpath within it*, and several volumes routinely share one
filesystem — Craft has a validator whose only job is stopping their subpaths overlapping, which only
makes sense because the arrangement is expected.

`SystemReporter` read the filesystem's root and stopped there. So on a site with `images`, `invoices`
and `logos` on one shared filesystem, all three walked the same tree and reported byte-identical
sizes and file counts. Because the platform adds the volumes together for its "Craft holds" total,
that site read three times its real size — a number plausible enough to go unquestioned, which is the
worst kind of wrong.

Volumes now resolve through Craft's own `getRootPath()` where it exists — which parses the
environment variable, normalises separators and follows symlinks, rather than only the first of those
— joined to the volume's own subpath. Directories already walked in a run are remembered, so a
misconfiguration that made two volumes collide cannot spend the walk budget twice and leave the
storage directory unmeasured.

Nothing about the report's shape changed; `system.v1` is untouched. A site will simply see its
volumes separate, and its total fall, on the next system report.

### Release notes now cross the wire

Requires `manager-protocol ^1.3` and sends `updates.v2`.

Each plugin with an update available now carries the releases between the version it is running and
the version it could run: the version, the note the plugin published, whether it was flagged
critical, and the date. This is what makes Manager able to show "what changed between these versions"
for a plugin, the way it already does for Craft itself.

The previous version of this file said, in as many words, that forwarding release notes would put a
description of an unpatched vulnerability attached to a named site into a dashboard. The half of that
which is true is "attached to a named site". The notes themselves are public — the Craft Plugin Store
hands them to anybody who asks — and this site already holds a copy, which is precisely why they can
be sent **without the connector or the platform making a single request to anyone**. The alternative
design, where the platform resolves plugin handles against the Plugin Store, would have told a third
party which plugins every site in a fleet runs.

What was never safe was the association, and that is a decision made at the receiving end.
`updates.v2.json` states the obligation in its own description and the platform enforces it: notes are
stored against a plugin and a version, never against a site.

Bounded twice — ten releases per plugin, four thousand characters per note, and a budget across the
whole report so a site with two hundred outdated plugins still produces something receivable. Notes
are sent only for a plugin that has an update available; an up-to-date plugin is the same shape it
was under v1.

## 1.7.1

A capability that has not been granted is no longer an error.

Four tasks checked whether the platform had granted the capability they need and threw if it had
not. On Craft that means a failed queue job, and the schedule repeats — so a site whose owner had
not granted `logins:read` collected a failed job every thirty minutes, indefinitely, in their own
control panel, from the plugin that is meant to be watching the site for them.

Being refused is the permission system working, not a fault. Manager's own interface says so in as
many words: a capability that is missing is *skipped rather than passed*. These tasks now agree with
it — they return a "skipped" outcome, which `RunTask` logs like any other, so the reason is still
findable without also being an alarm.

Nothing becomes stuck as a result. The capability list is refreshed from the platform's response to
the jobs task, which runs every five minutes, so granting one takes effect on its own.

Affects `report` (`inventory:read`), `updates` (`updates:read`), `system` (`runtime:read`) and
`logins` (`logins:read`). Nothing else changes: the checks still happen, and a task without
permission still sends nothing.

## 1.7.0

Backups this platform cannot read.

Requires protocol 1.2.0, and a Manager installation new enough to serve recovery keys. A 1.7.0
connector talking to an older platform keeps producing v1 artifacts exactly as before.

### The change

Under the old arrangement an artifact's encryption key was sealed to *Manager's* public key, and
Manager opened it on arrival. That was stated honestly — the documentation said in as many words that
it was not end-to-end encryption — but it meant anybody holding Manager's backup secret key and its
storage could read every backup it held.

Now the key is sealed to the organisation's own recovery keys and to nothing else. Manager stores,
verifies, serves and deletes something it cannot open.

### Pinning, which is the part that matters

Sealing to a customer's key is worth nothing if Manager can choose which key that is. It has to name
them — this site has no other way to learn them — so a compromised or compelled installation could name
one of its own, and this site would encrypt to it. No error, no missing backup. Just a backup somebody
else can read.

So `config/manager-connector.php` gains `recoveryKeyFingerprints`. Every key Manager offers is
fingerprinted **from its key material**, not from the label Manager attached to it, and compared against
that list. An unpinned key fails the whole backup rather than being filtered out of it, because a
response containing one is evidence of a misconfiguration or an attack and quietly dropping it would
hide both. The check runs before `takeDump()`, so a refused backup never writes a plaintext database to
disk.

The setting is config-file only and there is a build check asserting it never appears in the settings
template. A hijacked control-panel session that could re-pin this site would defeat the only control
that works.

Also new: `requirePinnedRecoveryKeys` (off by default — see the docs for why that compromise), and
`backupUploadHost` for direct-to-storage uploads, where Manager supplies a path and a query string and
never a host.

### The ratchet

`{{%managerconnector_connection}}` gains `backupFormatFloor`. It goes to `v2` the first time this site
finds pinned fingerprints or completes a v2 backup, and no response from Manager can lower it —
`updateBackupPublicKey()` becomes a logging no-op and there is no v1 code path left to reach. Lowering
it means editing this server.

This is the only downgrade control that survives Manager being the adversary. Every other defence is
something Manager participates in, and therefore something a compromised Manager can decline to perform.

### Build checks

`bin/verify-invariants.php` gains a ninth check covering all of the above: that the pin is read from
local settings rather than from the wire, that fingerprints are derived from key material, that an
unpinned key throws rather than continues, that the check precedes the dump, that the ratchet has no
inverse, and that none of the three settings is exposed in the control panel.

Two existing checks were made tolerant of whitespace and concatenation style while doing this. They
matched exact source strings, and a change in `craftcms/ecs` — an unpinned `dev-main` dependency —
turned `! $x` into `!$x` across the repository and silently disabled both. A security check that a
formatter can switch off while still reporting success is worse than no check.

### One progress report

`POST /api/connector/v1/backups/progress`, sent once, when the dump starts. It is the longest phase and
the only one whose information is not derivable from a later report; everything else would be a signed
request consuming a nonce and a rate-limit slot to record something already implied, and a report that
can be lost is a state that can lie. It carries a job identifier, one word from a fixed list, and a
timestamp — no path, no byte count, no table name.

### Fixed

- `ResponseSampler` called `Plugin::instanceOrNull()`, which exists on no class in the hierarchy and
  was additionally resolving against the `services` namespace rather than the plugin's. The resulting
  `Error` was swallowed by the surrounding catch, so **no response time was ever recorded** and the
  runtime report's `response` section was silently always absent — indistinguishable from a site that
  had turned the setting off.

## 1.6.0

Requires `coysh-digital/manager-protocol` 1.1.0.

Three new things a site can report, each behind a capability of its own that has to be granted
deliberately. None of them is folded into an existing grant: measuring disk means walking a directory
tree and timing responses means observing traffic, and widening `system:read` to cover them would
have had sites start doing both without anybody deciding to.

- **Disk usage** (`runtime:read`, `system.v1`). Byte and file counts per asset volume, by handle, plus
  free and total space. Never a path, a file name or a listing — a byte count says how much is there
  and nothing about what. The walk is bounded by `storageWalkSeconds` (five by default); a volume that
  runs out of budget, or that lives on remote storage, is reported as **unmeasured** rather than as
  empty. Those are different facts and only one of them should worry anybody: a partial figure
  presented as a total is how somebody concludes an asset volume was emptied overnight.
- **PHP limits** (`runtime:read`, `system.v1`). Memory, execution time, upload and post size, input
  vars, opcache state and memory, and a count of loaded extensions. Never `phpinfo()`, never an ini
  path, never the list of extensions, and never a setting whose value would name the host.
- **Response times** (`runtime:read`, `system.v1`). Mean, median, 95th percentile and slowest, from a
  fixed ring of up to 200 samples taken from traffic the site was already serving. It stores a
  duration and nothing else — no URL, no visitor, no address. **This is server render time, not time
  to first byte:** it excludes DNS, TLS, queueing in front of PHP and the network to the visitor, so a
  site with a two-second TTFB and a 40ms render looks fast in it and is not. Controlled by
  `sampleResponseTimes`, on by default; the sampler swallows every error, because a visitor's page
  must not fail because a measurement of it did.
- **Failed sign-ins** (`logins:read`, `logins.v1`). Four counts and a timestamp: attempts, accounts
  affected, accounts locked out, and how many of those are administrators. Read from Craft's own
  `invalidLoginCount`, `lastInvalidLoginDate` and `lockoutDate` rather than by listening for login
  failures and keeping a log — a record of who tried to sign in as whom, from where, is a log of real
  people's behaviour on somebody else's website, and there is no stated purpose for collecting it.
  **Never a username, an email address, a user id or a source address.** Note the figures are a floor
  rather than a total: Craft resets an account's counter on a successful sign-in, so somebody who
  eventually guessed correctly leaves nothing behind in them.

Also:

- `manager-connector/preview` now covers **every** report the connector can produce, not just
  inventory, and shows each one whether or not its capability is granted — the question it answers is
  "what would this reveal if I turned it on", which has to be answerable before turning it on. Use
  `--report=logins` for one at a time.
- New commands `manager-connector/system` and `manager-connector/logins`. On the schedule, sign-in
  counters run half-hourly and the runtime report six-hourly: disk usage moves over days, and a
  directory walk every hour on a million-file volume is a cost the site pays for a number nobody reads
  that often.

## 1.5.0

- **Cron is no longer required.** `webTrigger`, on by default, drives the schedule from ordinary web
  traffic: when a task is due the next request pushes a queue job and returns, and Craft's queue does
  the work. Plenty of Craft sites run on hosting where nobody can add a cron entry, and requiring one
  meant the plugin simply did not work there.
- Traffic cannot amplify it. Each task fires at most once per interval however busy the site is — the
  claim is an atomic cache write, so two simultaneous requests cannot both take it. Fifteen requests
  with an empty throttle produce four jobs, one per task.
- It is not an endpoint: a listener on requests that were happening anyway, reading nothing from the
  request, with no URL that reaches it.
- Cron remains the recommendation where it is available, and the documentation says why: a quiet site
  reports less often, and this depends on Craft's queue running where cron does not.
- The four tasks now live in one service shared by the console commands and the queue job, so the two
  routes cannot drift.
- `useQueue` was declared, documented as working, and never read by anything. Superseded by
  `webTrigger`, which does what it claimed to.

## Unreleased

- **Correction to the 1.4.0 note below.** It claimed a plugin settings page stays linked with
  `allowAdminChanges` off, "checked rather than assumed". The check was wrong: the testbed had
  `CRAFT_ALLOW_ADMIN_CHANGES=true` in its environment, which takes precedence over
  `config/general.php`, so the setting under test was never actually applied. Retested properly, Craft
  renders the plugins list but does **not** link a plugin's settings page when admin changes are off.
  Moving the screen to Utilities was therefore necessary rather than merely tidier, and enrolment
  behind plugin settings would have been unreachable on hardened production sites.

## 1.4.1

- An icon, in the same family as the other Coysh Digital Craft plugins: one site reporting outbound to
  one platform, in the red Manager uses.
- Full documentation in `docs/`, covering pairing, exactly what is and is not sent, what each capability
  permits, how backups work, the security model, every console command, and troubleshooting.

## 1.4.0

- The connector's screen moved from plugin settings to **Utilities**. Not for reachability — a plugin
  settings page stays linked and reachable with `allowAdminChanges` off, which was checked rather than
  assumed. It moved because of what the screen is: Settings holds things that are set, Utilities holds
  things you do, alongside Craft's own Updates, Caches and Database Backup.
- Pairing now needs the `utility:manager-connector` permission rather than Craft administrator, so an
  owner can let the person who looks after a site connect it without making them an administrator.
  That permission carries real authority and the screen says so.
- This plugin now registers no URL rules at all. Craft routes the utility, and the two actions go
  through Craft's action mechanism — POST with a CSRF token — so nothing it registers can be reached
  by following a link.

## 1.3.0

- Pair and disconnect from the control panel. The console still works, but requiring SSH excluded
  every site on managed hosting without a shell, which is an exclusion rather than an inconvenience.
- Fixed a redirect loop on the plugin settings screen. It redirected to itself, so the page could not
  be opened at all.
- Refuses a platform URL that is not HTTPS. The setting previously supplied `https` as a *default*
  scheme, which silently accepts an explicit `http://` — and a signed request over plain HTTP is
  still readable in transit, including the enrolment code used to pair.
- When `platformUrl` is set in `config/manager-connector.php`, the control panel cannot override it.
  On a site configured that way, a hijacked session can only pair with the platform already chosen.

## 1.2.0

- Runs `backup.create` jobs. The database is dumped through Craft's own backup, using the connection
  the site already has, then encrypted here with a key generated on this server and sealed to the
  platform. The plaintext dump is deleted whether the upload succeeds or not.
- Backups are uploaded to the platform this site paired with and nowhere else. The job carries no
  destination and there is no argument anywhere in the upload path that could supply one, so a
  compromised platform can ask for a backup but cannot ask for one to be sent elsewhere.
- Refuses to back up at all if the platform has published no encryption key. A database never travels
  in the clear.
- Learns the platform's artifact encryption key from signature-verified responses only, so a rotation
  is followed automatically and an intercepted response cannot substitute a key.
- `uploadTimeout` and `maxBackupMegabytes` settings.

## 1.1.0

- Reports available Craft and plugin updates, including whether any release in between is flagged
  critical. Release notes are deliberately not sent: they describe what a version fixes, and
  forwarding them would put a description of an unpatched vulnerability, attached to your site, into
  a dashboard.
- Claims and runs jobs from the platform's registry, refusing any type this version does not
  implement.
- `manager-connector/updates` and `manager-connector/jobs`.

## 1.0.0

Initial release.

- Pairs with a Manager platform using a single-use enrolment code.
- Generates its Ed25519 keypair locally; the private key never leaves the installation.
- Signs every outbound request, and verifies the platform's signature on the pairing response.
- Reports operational metadata against the shared `inventory.v1` allowlist.
- Exposes no inbound management endpoint.

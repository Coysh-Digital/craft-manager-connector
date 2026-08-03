# Console commands

Eight commands, one per thing you might want to do. All of them are safe to run by hand at any time.

## Reporting

### `manager-connector/heartbeat`

Says "still here". Carries no site data beyond this plugin's version — reporting anything more would be
collection without a stated purpose.

This is what makes a site look alive in the fleet table. Without it Manager will eventually report the
site as not reporting, which is accurate but not what you want.

**Run every 5 minutes.**

### `manager-connector/report`

Sends the inventory report: versions, plugin and package lists, and whatever the site's capabilities
permit. See [What is sent](/what-is-sent).

**Run hourly.** More often achieves little — the answers change when you deploy, not by the minute.

### `manager-connector/updates`

Checks installed versions against published releases and reports what is available, including whether
anything in between is flagged as a security release. Requires `updates:read`.

**Run daily.** It makes an outbound request to Craft's own update service, so there is no value in
hammering it.

### `manager-connector/system`

Reports disk usage per asset volume, free space, PHP's numeric limits and opcache state, and how long
the site has been taking to build its own pages. Requires `runtime:read`.

**Run every 6 hours.** This is the one genuinely expensive command: it walks the asset volumes, and a
volume with a million files on network storage takes real time. Disk usage moves over days, so hourly
would be paying a cost every hour for a number nobody reads that often. The walk is bounded by
`storageWalkSeconds`; a volume that runs out of budget is reported as unmeasured rather than as empty,
and keeps the bytes it did reach so Manager can show them as a floor.

A volume ends up unmeasured for one of three reasons, and from 1.12.0 the report says which: it is on
remote storage and walking it would mean an API call per directory billed to you; the walk ran out of
budget; or its path could not be opened, which is the only one of the three that is a fault. Each
volume also reports whether it is on this server or on remote storage, so Manager can say that a
remote volume's bytes are not on your disk. Neither field names a provider, a bucket or a region.

### `manager-connector/logins`

Reports counts of failed control-panel sign-ins — attempts, accounts affected, accounts locked out,
and how many of those are administrators. Never usernames or addresses. Requires `logins:read`.

**Run every 30 minutes.** It is one indexed query, and frequent enough that an attack in progress is
visible while it is still in progress.

### `manager-connector/jobs`

Claims and runs whatever work Manager has queued for this site — an immediate inventory refresh, an
update check, or a backup.

**Run every 5 minutes.** This is what makes a button in Manager appear to do something promptly.

## Pairing

### `manager-connector/pair <code>`

Pairs this site using a single-use enrolment code.

```bash
php craft manager-connector/pair mgr_enrol_...
php craft manager-connector/pair --platformUrl=https://manager.example.org mgr_enrol_...
```

The `--platformUrl` option is only needed when `platformUrl` is not in your config file. See
[Pairing](/pairing).

### `manager-connector/disconnect`

Deletes this site's signing key. The credentials stop working immediately.

Revoke the connector in Manager as well — the platform cannot know a site has disconnected itself.

## Inspecting

### `manager-connector/status`

Prints the connection state, the platform address, the site identifier, the granted capabilities, when
the last successful request was, this plugin's version, and when the credentials were last rotated.

The first thing to run when something is not reporting.

### `manager-connector/preview`

Prints the exact payloads this site would send, without sending them.

Worth running before you pair, if you want to see what you are agreeing to rather than read a page about
it. It uses the same builders the real reports use, so there is no version of it that could differ from
what actually goes out.

Covers **every** report — inventory, updates, system and logins — and shows each one whether or not its
capability has been granted, marking which is which. That is deliberate: the question this answers is
"what would this reveal if I turned it on", which has to be answerable before turning it on.

Add `--report=logins` for one at a time. Valid values are `report`, `updates`, `system` and `logins`.

## Scheduling all of it

```cron
*/5 * * * * cd /path/to/site && php craft manager-connector/heartbeat
*/5 * * * * cd /path/to/site && php craft manager-connector/jobs
*/30 * * * * cd /path/to/site && php craft manager-connector/logins
0 * * * *   cd /path/to/site && php craft manager-connector/report
0 */6 * * * cd /path/to/site && php craft manager-connector/system
0 6 * * *   cd /path/to/site && php craft manager-connector/updates
```

Schedule all six even if the site has not been granted every capability. A command whose capability is
missing exits immediately saying so and costs nothing — whereas a cron entry nobody adds when the
capability is granted six months later produces a screen that reads "granted, but nothing reported
yet" indefinitely, with no obvious reason why.

### If you cannot use cron

Plenty of Craft sites live on hosting where nobody can add a cron entry. You do not need one.

`webTrigger` is **on by default**, and drives the same schedule from ordinary web traffic. When a task
is due, the next request to the site pushes a queue job and returns; Craft's queue does the work, so
the visitor whose request triggered it waits for nothing.

Traffic cannot amplify it. Whether the site gets ten requests an hour or ten thousand, each task fires
at most once per interval — the claim is an atomic cache write, so two simultaneous requests cannot
both take it.

What it needs is for Craft's queue to actually run. That is Craft's default (`runQueueAutomatically`).
If you have turned that off, something else has to run the queue — a queue daemon, `craft-async-queue`,
or a cron entry, in which case you may as well schedule the connector directly.

Two honest limitations, which is why cron is still the recommendation where it is available:

- **A quiet site reports less often.** No traffic overnight means nothing reported overnight. Manager
  will notice the gap and say the site is not reporting, which is correct but may not be what you want
  to see every morning.
- **It depends on the queue.** A stalled Craft queue means a silent site. With cron, the connector does
  not depend on the queue at all.

Turn it off with `'webTrigger' => false` in `config/manager-connector.php` if you have cron and would
rather the schedule came from one place.

## Settings

`config/manager-connector.php`, which you create yourself — nothing publishes it, and every default
below applies to a site that has no such file:

| Setting | Default | What it does |
|---|---|---|
| `platformUrl` | empty | The Manager installation to report to. Setting it here fixes it: the control panel cannot then change it |
| `timeout` | `10` | Seconds to wait for the platform. Short on purpose — a slow platform must never become a slow website |
| `uploadTimeout` | `900` | Seconds to wait while uploading a backup, which is measured in megabytes rather than milliseconds. Raise it on a site with a large database — a multi-gigabyte artifact on a slow uplink takes hours |
| `maxBackupMegabytes` | `2048` | Largest database this connector will attempt to back up. A safety valve, not a policy. Ignored by Manager Cloud, which meters and bills the storage instead |
| `sampleResponseTimes` | `true` | Time the site's own responses for the runtime report. One cache write per request into a fixed ring of 200 durations — a number and nothing else, no URL, visitor or address. Nothing is transmitted without `runtime:read` |
| `storageWalkSeconds` | `5` | Seconds to spend measuring asset volumes before giving up on the rest. A volume that runs out of budget is reported as unmeasured rather than as empty |
| `useQueue` | `false` | Also send the heartbeat through Craft's queue |

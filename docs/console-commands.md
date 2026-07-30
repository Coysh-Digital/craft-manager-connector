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

Prints the exact payload this site would send, without sending it.

Worth running before you pair, if you want to see what you are agreeing to rather than read a page about
it. It uses the same builder the real report uses, so there is no version of it that could differ from
what actually goes out.

## Scheduling all of it

```cron
*/5 * * * * cd /path/to/site && php craft manager-connector/heartbeat
*/5 * * * * cd /path/to/site && php craft manager-connector/jobs
0 * * * *   cd /path/to/site && php craft manager-connector/report
0 6 * * *   cd /path/to/site && php craft manager-connector/updates
```

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

`config/manager-connector.php`:

| Setting | Default | What it does |
|---|---|---|
| `platformUrl` | empty | The Manager installation to report to. Setting it here fixes it: the control panel cannot then change it |
| `timeout` | `10` | Seconds to wait for the platform. Short on purpose — a slow platform must never become a slow website |
| `uploadTimeout` | `900` | Seconds to wait while uploading a backup, which is measured in megabytes rather than milliseconds |
| `maxBackupMegabytes` | `2048` | Largest database this connector will attempt to back up. A safety valve, not a policy |
| `useQueue` | `false` | Also send the heartbeat through Craft's queue |

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

### Through Craft's queue instead

If a queue worker is definitely running, set `useQueue` to `true` in `config/manager-connector.php` and
the heartbeat will also be pushed through Craft's queue.

Leave it off unless you are sure. A stalled queue makes a healthy site look offline, which is a worse
failure than not having the extra reporting path.

## Settings

`config/manager-connector.php`:

| Setting | Default | What it does |
|---|---|---|
| `platformUrl` | empty | The Manager installation to report to. Setting it here fixes it: the control panel cannot then change it |
| `timeout` | `10` | Seconds to wait for the platform. Short on purpose — a slow platform must never become a slow website |
| `uploadTimeout` | `900` | Seconds to wait while uploading a backup, which is measured in megabytes rather than milliseconds |
| `maxBackupMegabytes` | `2048` | Largest database this connector will attempt to back up. A safety valve, not a policy |
| `useQueue` | `false` | Also send the heartbeat through Craft's queue |

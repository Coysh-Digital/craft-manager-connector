# Troubleshooting

## Start here

```bash
php craft manager-connector/status
```

That tells you the connection state, the platform address, the granted capabilities and when the last
successful request was. Most questions are answered by it.

## "This site is not paired with a Manager platform"

The plugin holds no identity. Either it was never paired, or something deleted the connection — a
`disconnect`, or the plugin being uninstalled and reinstalled.

Issue a fresh enrolment code in Manager and pair again. See [Pairing](/pairing).

## Pairing fails

**"The Manager platform URL must use HTTPS."** Exactly what it says, and there is no override. A signed
request over plain HTTP is still readable, including the enrolment code you are about to send.

**"The platform rejected the request (HTTP 422)."** The code is wrong, expired, or already used. Codes
are single-use and expire after fifteen minutes by default, and issuing a new one invalidates the
previous one. Issue another.

**"The platform response failed signature verification and was discarded."** The reply did not carry a
valid signature from the platform it claims to be. Treat this seriously: it means either the address is
wrong, or something is intercepting the connection. Check the address before retrying.

**"Could not reach the Manager platform."** Outbound HTTPS from this server to the platform is not
working. Check from the server itself:

```bash
curl -sS -o /dev/null -w '%{http_code}\n' https://manager.example.org/up
```

`200` means the platform is reachable and healthy. Anything else is a network or platform problem rather
than a plugin one.

## Paired, but nothing appears in Manager

**Is anything running?** Nothing is reported unless something asks. Check that cron is actually running
the commands — `manager-connector/status` shows the last successful request, and if that is "never" then
nothing has ever run.

**Waiting for confirmation?** If the hostname this site reported differs from the domain Manager expected,
the pairing is held and nothing is reported until somebody approves it on the site's page in Manager.
`status` shows the state as `pending_confirmation`.

**Reporting, but sections are missing?** A site only sends what its capabilities permit. If licence state
or queue depth is absent, grant `licences:read` or `system:read` in Manager. `status` lists what has been
granted.

## The site shows as connected in Manager after I disconnected it

Expected. `disconnect` deletes the key from this site; it does not tell the platform, because a platform
should not take a site's word for its own revocation.

Revoke the connector in Manager too. Left alone, the platform notices the silence and raises a finding,
which is correct but slower than saying so.

## The plugin settings screen redirects

It redirects to **Utilities → Manager Connector**, which is where the screen lives. That is intended.

If you get a redirect *loop*, you are on a version before 1.3.0 — upgrade.

## The utility is not in the Utilities list

It is permission-controlled. Grant **Manager Connector** under the user's utility permissions.

Note that it remains available with `allowAdminChanges` disabled, which is deliberate: a locked-down
production site is exactly where you cannot reach a console.

## A backup job fails

**"the platform has no artifact encryption key configured."** Manager has not had its backup keypair
generated. On the platform: `php artisan manager:backups:keygen`. Until then this plugin refuses rather
than uploading an unencrypted database.

**"this site has not been granted permission to create backups."** Grant `backups:create` in Manager. It
is never granted at pairing and needs its own confirmation.

**"the database is larger than this connector is configured to back up."** Raise `maxBackupMegabytes` in
`config/manager-connector.php`, having first checked the size is what you expect. The limit exists so an
unexpectedly large dump fails early rather than filling the disk.

**"the database is larger than the platform will accept."** A different limit from the one above, and
not one you can change here — it belongs to the Manager installation you report to. Self-hosted, raise
`MANAGER_BACKUP_MAX_BYTES` there, or unset it for no ceiling; the refusal names the current one. On
Manager Cloud the storage is metered and billed rather than capped, so this should not appear.

**"The platform rejected the artifact (HTTP 413)."** Which of the two messages follows matters, and
they point in opposite directions.

If it names a correlation ID, Manager refused it and wrote a line you can look up. Give that ID to
whoever runs the platform.

If instead it says *no correlation ID was returned and the response was not JSON*, the request never
reached Manager. A proxy or web server in front of it — nginx, Cloudflare, a load balancer — answered
with its own error page first, so there is nothing in the platform's log to find and the platform's
own size ceiling is not the cause. The fix is on the platform host: `client_max_body_size` in nginx
(**the default is 1 MB**, which rejects essentially every real backup) and `post_max_size` in PHP.
A live console ran at `client_max_body_size 2m` and refused a 2.1 MB database nightly for four
nights before anyone looked past the ceiling.

**"there is not enough free disk to take a backup safely."** A backup holds the dump and the encrypted
copy at once, so it needs about twice the dump. The message gives both numbers. Free space, or move
Craft's backup directory to a volume that has it — the check runs before anything is written, so
nothing has been left behind.

**"part N of M could not be uploaded."** Only on sites using a direct upload host, and only for
artifacts large enough to be sent in parts. Each part is retried three times on its own before the
backup fails, so a single dropped connection does not cost the whole upload. Persistent failures are
usually a proxy with its own body-size limit between the site and storage.

**"the database backup did not complete."** Craft's own backup failed. Try `php craft db/backup` directly
— if that fails too, the problem is the database or the disk, not this plugin.

## Everything looks right and it still will not report

```bash
php craft manager-connector/preview
```

If that produces a payload, the plugin is working and the problem is between here and the platform. If it
errors, the error names what is wrong.

Failing that, Craft's logs hold what the plugin recorded, under `storage/logs/`. Grep for
`manager-connector`. Every message it writes is its own — no message contains a secret, an enrolment code
or any site content, so a log is safe to send on if you need help reading it.

## Reporting a problem

Bugs: [GitHub issues](https://github.com/Coysh-Digital/craft-manager-connector/issues).

Security issues: privately to **hello@coysh.digital**, not a public issue.

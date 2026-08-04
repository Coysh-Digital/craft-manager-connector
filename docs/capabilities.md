# Capabilities

What Manager may ask this site for is a list of named permissions, granted per site. A newly paired
site gets one of them.

## The set

| Capability | Granted at pairing | What it permits |
|---|---|---|
| `inventory:read` | Yes | Craft and PHP versions, database engine and version, plugin and Composer package versions |
| `updates:read` | No | Checking installed versions against published releases, and reporting what is available |
| `licences:read` | No | Whether Craft and plugin licences are valid, on trial or expiring. Never the keys |
| `security:read` | No | Safe configuration booleans: dev mode, HTTPS enforcement, admin changes, updates in production |
| `system:read` | No | Queue depth, failed job count, pending migration count, environment classification |
| `runtime:read` | No | Disk usage per asset volume, free space, PHP's numeric limits and opcache state, and how long the site takes to build its own pages |
| `logins:read` | No | Counts of failed control-panel sign-ins, and how many accounts are locked out |
| `backups:create` | **Never** | Taking a database backup, encrypting it and uploading it |

### `runtime:read` and why it is separate from `system:read`

The two look similar and are not. `system:read` reads numbers Craft already has in memory - the queue
depth, the migration count. `runtime:read` **walks directory trees** to size the asset volumes, and
**times requests** the site is serving.

Both are a different kind of work from reading a version number, so they need a different decision.
Folding them into `system:read` would have meant every site that granted that months ago quietly
starting to do both, which is not something a permission should be able to do to you.

What it sends: byte and file counts per volume, by handle; free and total disk space; PHP's memory,
execution, upload and post limits; opcache state and memory; a count of loaded extensions; and mean,
median, 95th-percentile and slowest render times.

What it never sends: a path, a file name, a directory listing, `phpinfo()`, an ini path, the list of
extensions, or any URL, visitor or address from the requests it timed. A volume it cannot walk - too
large for the time budget, or on remote storage - is reported as *unmeasured*, never as empty.

The timings are **server render time, not time to first byte**. The connector times its own site from
inside the PHP process, so DNS, TLS, queueing in front of PHP and the network to the visitor are all
excluded. A site with a slow TTFB and a fast render looks fast in this figure and is not.

Turn the timing off with `sampleResponseTimes` in the plugin config; adjust the directory-walk budget
with `storageWalkSeconds`.

### `logins:read`

Four counts and one timestamp: attempts, accounts affected, accounts locked out, and how many of the
affected accounts are administrators.

**Never a username, an email address, a user id or the address anybody connected from.** These are
read from Craft's own `invalidLoginCount`, `lastInvalidLoginDate` and `lockoutDate` columns rather
than by listening for login failures and keeping a log - a record of who tried to sign in as whom,
from where, is a log of real people's behaviour on your website, and there is no reason for a
monitoring platform to hold one.

One caveat travels with the numbers: Craft resets an account's counter when somebody signs in
successfully, so the totals are a **floor, not a total**. An attempt that eventually worked leaves
nothing behind in them.

## Read-only by default

Pairing grants `inventory:read` and nothing else. Everything further is a deliberate act by somebody
in Manager, per site, recorded with who did it and when.

The five read capabilities can be granted with a switch. `backups:create` cannot - see below.

## Revoking

Any capability can be revoked at any time from the site's Capabilities screen in Manager. Revocation
takes effect on the next request: the plugin learns its current capability set from every signed
response, so it stops collecting what it no longer may collect without waiting for anything.

Revoking is deliberately easier than granting. Making a permission harder to withdraw than to give
would be the wrong way round.

## `backups:create` is different

It is never granted at pairing, and it is not offered as a switch beside the read-only ones. Granting
it requires, in Manager:

- an administrator, with a recent password confirmation
- the site's name typed out, so the wrong tab is not enough
- an acknowledgement that a backup contains the site's entire database, including user accounts,
  password hashes, sessions and any personal information the site holds
- a reason, recorded

The acknowledgement wording is stored in the audit log verbatim, so the record shows what somebody was
told when they agreed rather than that they agreed to something.

If it is granted but the platform has published no encryption key, this plugin **refuses to take a
backup at all**. A database must never travel in the clear, so no key means no backup rather than an
unencrypted upload.

## What no capability permits

There is no capability - present or planned - that permits:

- running a Craft console command
- evaluating PHP
- running a query
- reading a file
- writing a file
- making an arbitrary HTTP request
- shell access

The code to do those things is not in this plugin. A script in the repository fails the build if it
appears, and the platform's job registry has no type that could express any of them.

## How the plugin knows

The capability list is not fixed at pairing. It arrives on every signed job-claim response, and the
plugin adopts it **only** from a response whose signature it has verified.

That matters in both directions. Without it, granting a capability in Manager would change nothing on
the site, and revoking one would leave the site still collecting. And because the list is only ever
adopted from a verified response, whatever sits between the site and the platform cannot decide what
the site is willing to report.

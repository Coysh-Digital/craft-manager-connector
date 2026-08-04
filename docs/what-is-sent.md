# What is sent

The honest answer to "what does this plugin tell you about my site", field by field. If you are
deciding whether to install it, this is the page to read.

## The short version

Version numbers, plugin handles, counts, and a small set of yes/no configuration facts. No content, no
user records, no secrets, no configuration values, no file contents, no log contents.

## Permitted, in full

Everything below is the complete list. It is enforced by a JSON Schema that both sides share, and the
platform **rejects** a report containing anything outside it rather than quietly dropping the extra
field. That means the boundary cannot drift without somebody noticing.

**Versions and platform**

- Craft version and edition
- PHP version
- Database engine and version
- This connector's version

**Installed packages**

- Plugin handles, names, versions, and whether each is enabled
- Composer package names and versions

**Licences** - requires `licences:read`

- Whether Craft's licence is valid, on trial, or has a problem
- The same for each plugin

Calculated on the site. Licence keys themselves are never transmitted.

**Configuration facts** - requires `security:read`

Booleans only:

- whether dev mode is on
- whether admin changes are allowed
- whether HTTPS is enforced
- whether the update process is permitted in production

The *values* of configuration settings are never sent, and neither is any environment variable.

**System state** - requires `system:read`

- Queue depth, and how many jobs have failed
- How many migrations are pending
- Environment classification: production, staging or development

Counts only. Job payloads are never read, because a queued job can carry site content.

**Disk, PHP limits and response times** - requires `runtime:read`

- Bytes and file count per asset volume, identified by its handle
- Free and total space on the volume Craft's storage directory sits on
- PHP's memory limit, execution time, upload and post size limits, and input-var limit
- Whether opcache is on, and how much of it is used
- How many PHP extensions are loaded - the count, not the list
- Mean, median, 95th-percentile and slowest page render times

Sizes, never names. A byte count says how much is there and nothing about what: no path, no file
name, no directory listing. A volume that cannot be walked inside the time budget, or that lives on
remote storage, is reported as **unmeasured** - deliberately distinguishable from empty, because a
partial figure presented as a total is how somebody concludes a volume was emptied overnight.

The render times come from a fixed ring of at most 200 samples taken from traffic the site was
already serving. Each sample is a duration and nothing else: no URL, no visitor, no address, no user
agent. And it is **server render time, not time to first byte** - DNS, TLS, queueing in front of PHP
and the network to the visitor are all outside what this can see.

**Failed sign-in counts** - requires `logins:read`

- How many control-panel sign-ins failed in the last 24 hours
- How many accounts that spans
- How many accounts are locked out
- How many of the affected accounts are administrators
- When the most recent failure was

Counts only, and never who. There is no field for a username, an email address, a user id or a source
address, and nothing keeps a per-attempt record - these are read from Craft's own counters rather
than by watching sign-ins happen.

Note the figures are a floor rather than a total: Craft clears an account's counter when somebody
signs in successfully, so an attempt that eventually worked leaves nothing behind in them.

## Refused, in full

Not "filtered", not "redacted" - a report containing any of these is rejected outright:

- entries, assets, categories, or any other content
- user records, email addresses, or password hashes
- customer or order records
- sessions or authentication tokens
- complete log files, or excerpts of them
- environment variable values
- Craft's security key
- licence keys
- API credentials of any kind
- database credentials or connection strings
- complete configuration files
- arbitrary file contents
- filesystem paths, file names or directory listings
- `phpinfo()` output, ini paths, or the list of loaded extensions
- the URL, visitor or address behind any request that was timed
- the username, email address or source address behind any failed sign-in

## Why a boolean and not the value

The pattern throughout is: report the *conclusion*, never the *evidence*.

"Dev mode is on" is what a fleet dashboard needs to know. The contents of `general.php` would tell you
that too, and also your security key, your allowed hostnames and whatever else is in there. So the
plugin computes the answer on your server and sends the answer.

The same logic covers licences - a state rather than a key - and the queue: a count rather than the
jobs, because a job payload can contain anything a developer put in it.

## Backups are different, and separate

A backup is a complete copy of your database, and everything above does not apply to it. It only
happens if you grant `backups:create`, which is granted per site, never at pairing, and needs its own
confirmation. See [Backups](/backups).

## Checking for yourself

Do not take this page's word for it:

```bash
php craft manager-connector/preview
```

That prints the exact payload this site would send, without sending it. It is the same builder the
real report uses, so there is no version of it that could differ from what goes out.

The schema is in the [`manager-protocol`](https://github.com/Coysh-Digital/manager-protocol) package,
which is MIT-licensed and has no dependencies. `schemas/inventory.v1.json` is the list above expressed
as `additionalProperties: false`, which is the mechanism that makes "refused" mean refused.

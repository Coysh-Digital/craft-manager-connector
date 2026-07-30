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

**Licences** — requires `licences:read`

- Whether Craft's licence is valid, on trial, or has a problem
- The same for each plugin

Calculated on the site. Licence keys themselves are never transmitted.

**Configuration facts** — requires `security:read`

Booleans only:

- whether dev mode is on
- whether admin changes are allowed
- whether HTTPS is enforced
- whether the update process is permitted in production

The *values* of configuration settings are never sent, and neither is any environment variable.

**System state** — requires `system:read`

- Queue depth, and how many jobs have failed
- How many migrations are pending
- Environment classification: production, staging or development

Counts only. Job payloads are never read, because a queued job can carry site content.

## Refused, in full

Not "filtered", not "redacted" — a report containing any of these is rejected outright:

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

## Why a boolean and not the value

The pattern throughout is: report the *conclusion*, never the *evidence*.

"Dev mode is on" is what a fleet dashboard needs to know. The contents of `general.php` would tell you
that too, and also your security key, your allowed hostnames and whatever else is in there. So the
plugin computes the answer on your server and sends the answer.

The same logic covers licences — a state rather than a key — and the queue: a count rather than the
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

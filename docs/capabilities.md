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
| `backups:create` | **Never** | Taking a database backup, encrypting it and uploading it |

## Read-only by default

Pairing grants `inventory:read` and nothing else. Everything further is a deliberate act by somebody
in Manager, per site, recorded with who did it and when.

The five read capabilities can be granted with a switch. `backups:create` cannot — see below.

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

There is no capability — present or planned — that permits:

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

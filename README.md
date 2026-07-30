# Manager Connector for Craft CMS

Reports operational metadata from a Craft CMS 5 installation to a [Manager](https://coysh.digital/manager)
platform over signed, outbound-only requests.

This plugin is privileged code running inside your production website, so it is published in full
and kept deliberately small. Read it before you install it — that is what it is here for.

## What it does

- Generates an Ed25519 keypair **on your server**. The private key never leaves it.
- Signs every request it sends, so the platform can prove it came from this installation.
- Reports version numbers, update availability, licence state and similar operational metadata.

## What it cannot do

These are not promises about intent; they are properties of the code.

| | |
|---|---|
| **No inbound endpoint** | The plugin registers no site or control-panel route that accepts management input. Every exchange is started by this plugin, outbound. It works from behind NAT with no inbound firewall rules. |
| **No remote execution** | There is no console-command runner, no PHP evaluation, no SQL, no shell, no arbitrary file access. Jobs come from a closed registry, and this plugin refuses any type it does not itself implement — so a compromised platform cannot make your site do something new. |
| **No credentials held** | Manager never receives an administrator password, an SSH credential or a database password. There is nowhere in its schema to put one. |
| **No site content** | What may be transmitted is fixed by a shared schema. The platform rejects anything outside it rather than quietly discarding the extra. |

## What it reports

Version numbers, plugin and Composer package names and versions, a handful of safe configuration
booleans, queue and migration counts, locally-computed licence state, and an environment
classification.

Never entries, assets, user records, password hashes, sessions, logs, environment-variable values,
security keys, licence keys, API credentials, database credentials or configuration file contents.

Do not take that on trust. See exactly what your site would send:

```bash
php craft manager-connector/preview
```

## Documentation

Full documentation lives in the [`docs`](docs/) folder, and is published at
[coysh.digital/plugins/manager-connector/docs](https://coysh.digital/plugins/manager-connector/docs/).
This README is the short version; the docs go deeper on pairing, exactly what is
and is not sent, what each capability permits, how backups work, the security
model, the console commands, and troubleshooting.

## Requirements

- Craft CMS 5.0 or later
- PHP 8.2 or later
- A Manager installation to report to, self-hosted or Manager Cloud

## Installation

```bash
composer require coysh-digital/craft-manager-connector
php craft plugin/install manager-connector
```

Set the platform URL in `config/manager-connector.php`:

```php
return [
    'platformUrl' => 'https://manager.example.org',
];
```

Kept in version control rather than in the database on purpose: pointing a site at a different
Manager platform should take a deployment.

## Pairing

Ask your administrator for a single-use enrolment code, then:

```bash
php craft manager-connector/pair mgr_enrol_...
```

The code is valid once and expires quickly. If the host this site serves from differs from the
domain the platform expected, pairing is held until a person confirms it — nothing is reported in
the meantime.

## Scheduling

```cron
*/5  * * * *  cd /path/to/site && php craft manager-connector/heartbeat
*/5  * * * *  cd /path/to/site && php craft manager-connector/jobs
0    * * * *  cd /path/to/site && php craft manager-connector/report
17   4 * * *  cd /path/to/site && php craft manager-connector/updates
```

The heartbeat carries no data at all. Hourly is plenty for the inventory report, since version
numbers only change when you deploy. The update check runs daily and at an odd minute, so a fleet of
sites does not all ask Craft's update service at once.

`jobs` is what makes anything the platform asks for actually happen. Nothing is pushed to your site —
the platform has no way to reach it — so this is the site choosing to ask.

## Commands

| Command | Purpose |
|---|---|
| `manager-connector/pair <code>` | Pair with a platform |
| `manager-connector/heartbeat` | Report liveness |
| `manager-connector/report` | Send an inventory report |
| `manager-connector/updates` | Check for updates and report what is available |
| `manager-connector/jobs` | Ask the platform whether there is anything to do, and do it |
| `manager-connector/preview` | Print what would be sent, without sending it |
| `manager-connector/status` | Show the current connection |
| `manager-connector/disconnect` | Delete this site's signing key |

Pairing and disconnection are console commands rather than buttons. Both are deliberate acts by
someone with server access, and behind a button a hijacked control-panel session could hand a
platform access to your site, or take it away.

## Disconnecting

```bash
php craft manager-connector/disconnect
```

The keypair is deleted outright rather than flagged, so there is nothing to reactivate.
Reconnecting needs a new enrolment code. Revoke the connector in Manager as well, so the platform
stops expecting it.

## Security

Report vulnerabilities privately to hello@coysh.digital. Please do not open a public issue.

Requires PHP 8.2+ and Craft CMS 5.0+. Dependencies are limited to the shared protocol package and
PHP's own `sodium` extension; HTTP goes through the Guzzle client Craft already ships.

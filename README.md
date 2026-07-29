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
| **No remote execution** | There is no console-command runner, no PHP evaluation, no SQL, no shell, no arbitrary file access. Version 1.0 reports and nothing else. |
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
*/5 * * * *  cd /path/to/site && php craft manager-connector/heartbeat
0   * * * *  cd /path/to/site && php craft manager-connector/report
```

The heartbeat carries no data at all; hourly is plenty for the report, since version numbers only
change when you deploy.

## Commands

| Command | Purpose |
|---|---|
| `manager-connector/pair <code>` | Pair with a platform |
| `manager-connector/heartbeat` | Report liveness |
| `manager-connector/report` | Send an inventory report |
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

Report vulnerabilities privately to security@coysh.digital. Please do not open a public issue.

Requires PHP 8.2+ and Craft CMS 5.0+. Dependencies are limited to the shared protocol package and
PHP's own `sodium` extension; HTTP goes through the Guzzle client Craft already ships.

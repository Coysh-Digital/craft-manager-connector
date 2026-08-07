# Manager Connector for Craft CMS

Reports operational metadata from a Craft CMS 4 or 5 installation to
[Manager for Craft](https://managerforcraft.com) over signed, outbound-only requests.

Requires PHP 8.1+ and Craft CMS 4.4+ or 5.0+.

## What it does

- Generates an ED25519 keypair **on your server**. The private key never leaves it.
- Signs every request it sends, so the platform can prove it came from this installation.
- Reports version numbers, update availability, licence state and similar operational metadata.

## What it cannot do

These are not promises about intent; they are properties of the code.

|---|---|
| **No inbound endpoint** | The plugin registers no site or control-panel route that accepts management input. Every exchange is started by this plugin, outbound. It works from behind NAT with no inbound firewall rules. |
| **No remote execution** | There is no console-command runner, no PHP evaluation, no SQL, no shell, no arbitrary file access. Jobs come from a closed registry, and this plugin refuses any type it does not itself implement - so a compromised platform cannot make your site do something new. |
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

Full documentation is at [managerforcraft.com/docs](https://managerforcraft.com/docs); its source
lives in the [`docs`](docs/) folder of this repository. This README is the short version. The docs
go deeper on pairing, exactly what is and is not sent, what each capability permits, how backups
work, the security model, the console commands, and troubleshooting.

## Requirements

- Craft CMS 4.4 or later, or Craft CMS 5.0 or later
- PHP 8.1 or later
  (Craft 4 itself runs on 8.0.2+, but this plugin does not - see the changelog for why)
- A Manager installation to report to, self-hosted or Manager Cloud

## Installation

```bash
composer require "coysh-digital/craft-manager-connector:^1.13.1" -w && php craft plugin/install manager-connector
```

Three details in that line, each of which has cost somebody a support message:

- **The version is pinned and quoted.** Unquoted, `^` is a glob operator in zsh with
  `extendedglob` set, which is the default on a lot of macOS setups - the shell eats the constraint
  before Composer sees it.
- **`-w`** is `--with-dependencies`, which lets Composer move the plugin's own dependencies rather
  than refusing because one of them is already locked. If Composer answers that a *root* requirement
  such as `craftcms/cms` is in the way, use `-W` (`--with-all-dependencies`) instead - Composer says
  so in as many words when it happens.
- **`&&` rather than two lines**, so the install does not run against a `composer require` that
  failed. `plugin/install` on a plugin Composer did not manage to add fails with a message about the
  handle, which sends people looking in the wrong place.

Installing does not create a config file, and the plugin runs without one - you are asked for the
platform address on the pairing screen instead. Creating it is optional and recommended:

```php
// config/manager-connector.php
return [
    'platformUrl' => 'https://manager.example.org',
];
```

Copy `vendor/coysh-digital/craft-manager-connector/src/config.php` if you want the commented version
of every option.

Kept in version control rather than in the database on purpose: pointing a site at a different
Manager platform should take a deployment. With it set, the pairing screen shows the address as
fixed, so this site cannot be repointed from the control panel. It is also the only place a recovery
key fingerprint can be pinned - see [Backups](docs/backups.md).

## Pairing

Ask your administrator for a single-use enrolment code, then:

```bash
php craft manager-connector/pair mgr_enrol_...
```

The code is valid once and expires quickly. If the host this site serves from differs from the
domain the platform expected, pairing is held until a person confirms it - nothing is reported in
the meantime.

## Scheduling

```cron
*/5  * * * *  cd /path/to/site && php craft manager-connector/heartbeat
*/5  * * * *  cd /path/to/site && php craft manager-connector/jobs
0    * * * *  cd /path/to/site && php craft manager-connector/report
17   4 * * *  cd /path/to/site && php craft manager-connector/updates
```

Scheduling is optional and takes the commands out of the queue. The plugin works just fine without cronjobs.

The heartbeat carries no data at all. Hourly is plenty for the inventory report, since version
numbers only change when you deploy. The update check runs daily and at an odd minute, so a fleet of
sites does not all ask Craft's update service at once.

`jobs` is what makes anything the platform asks for actually happen. Nothing is pushed to your site -
the platform has no way to reach it - so this is the site choosing to ask.

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

Report vulnerabilities privately to support@managerforcraft.com. Please do not open a public issue.

Dependencies are limited to the shared protocol package and PHP's own `sodium` extension; HTTP goes
through the Guzzle client Craft already ships.

## Licence

**MIT.** See [LICENSE.md](LICENSE.md).

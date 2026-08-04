# Installation

## Requirements

- Craft CMS 4.4 or later, or Craft CMS 5.0 or later
- PHP 8.1 or later
  (Craft 4 itself runs on 8.0.2+, but this plugin does not - see the changelog for why)
- The `sodium` extension, which ships with PHP and is almost always already enabled
- A Manager for Craft installation to report to

That last one is either your own - [Manager Self-Hosted](https://github.com/Coysh-Digital/manager) is
free and complete - or [Manager Cloud](https://managerforcraft.com), which is the same core hosted by
Coysh Digital. This plugin is identical for both, so the choice is about who runs the server rather than
what you get.

No inbound firewall rule is needed. The plugin only ever makes outbound HTTPS requests.

## Install with Composer

```bash
composer require coysh-digital/craft-manager-connector
php craft plugin/install manager-connector
```

## Install from the Plugin Store

Search for **Manager Connector** in **Settings → Plugins**, then install it. On a site with
`allowAdminChanges` disabled the Plugin Store is unavailable, so use Composer.

## Point it at your platform

Optional, and recommended. Create `config/manager-connector.php`:

```php
<?php

return [
    'platformUrl' => 'https://manager.example.org',
];
```

Setting it here rather than in the control panel does two things. It means pointing a site at a
different Manager takes a deployment, which is the intended amount of friction. And it means the
pairing screen **cannot** be used to send this site's data somewhere else: when the address is
configured, the form shows it as fixed rather than editable.

If you cannot deploy a config file - managed hosting with no shell and no file access - leave it out
and enter the address on the pairing screen instead. See [Pairing](/pairing).

## Then pair it

Go to **Utilities → Manager Connector**, paste the enrolment code from Manager, and press
**Pair this site**. Or on the console:

```bash
php craft manager-connector/pair mgr_enrol_...
```

Full detail in [Pairing](/pairing).

## Schedule the reports

Nothing is reported until something asks the plugin to. There are two ways to arrange that, and the
second needs no server access at all.

### If you cannot use cron

Nothing to do - it is already on. `webTrigger` drives the schedule from ordinary web traffic: when a
task is due, the next request pushes a queue job and Craft's queue does the work. Each task fires at
most once per interval however busy the site is.

It needs Craft's queue to be running, which is Craft's default. See
[Console commands](/console-commands#if-you-cannot-use-cron) for the two limitations and how to turn it
off.

### With cron, which is more predictable

Better where you have it, because it does not depend on the queue and does not go quiet when the site
does. Add to cron:

```cron
*/5 * * * * cd /path/to/site && php craft manager-connector/heartbeat
0 * * * *   cd /path/to/site && php craft manager-connector/report
0 6 * * *   cd /path/to/site && php craft manager-connector/updates
*/5 * * * * cd /path/to/site && php craft manager-connector/jobs
```

The heartbeat is what makes a site look alive in the fleet table; without it Manager will eventually
report the site as not reporting, which is correct but not what you want. See
[Console commands](/console-commands) for what each one does and how often it is worth running.

## Permissions

The pairing screen is a Craft utility, so access is controlled by the
**Manager Connector** utility permission under a user's permissions.

Granting it is a real decision. It lets the holder connect this site to a Manager platform, and a
platform decides for itself what it then asks the site for. Treat it the way you would treat access
to Craft's own Database Backup utility.

## Uninstalling

```bash
php craft manager-connector/disconnect
php craft plugin/uninstall manager-connector
composer remove coysh-digital/craft-manager-connector
```

Disconnect first. It deletes the signing key from this site, so the credentials stop working
immediately rather than at some point after somebody remembers. Then revoke the connector in Manager
as well, or the platform will keep expecting reports from a site that will never send any.

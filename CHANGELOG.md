# Changelog

## 1.5.0

- **Cron is no longer required.** `webTrigger`, on by default, drives the schedule from ordinary web
  traffic: when a task is due the next request pushes a queue job and returns, and Craft's queue does
  the work. Plenty of Craft sites run on hosting where nobody can add a cron entry, and requiring one
  meant the plugin simply did not work there.
- Traffic cannot amplify it. Each task fires at most once per interval however busy the site is — the
  claim is an atomic cache write, so two simultaneous requests cannot both take it. Fifteen requests
  with an empty throttle produce four jobs, one per task.
- It is not an endpoint: a listener on requests that were happening anyway, reading nothing from the
  request, with no URL that reaches it.
- Cron remains the recommendation where it is available, and the documentation says why: a quiet site
  reports less often, and this depends on Craft's queue running where cron does not.
- The four tasks now live in one service shared by the console commands and the queue job, so the two
  routes cannot drift.
- `useQueue` was declared, documented as working, and never read by anything. Superseded by
  `webTrigger`, which does what it claimed to.

## Unreleased

- **Correction to the 1.4.0 note below.** It claimed a plugin settings page stays linked with
  `allowAdminChanges` off, "checked rather than assumed". The check was wrong: the testbed had
  `CRAFT_ALLOW_ADMIN_CHANGES=true` in its environment, which takes precedence over
  `config/general.php`, so the setting under test was never actually applied. Retested properly, Craft
  renders the plugins list but does **not** link a plugin's settings page when admin changes are off.
  Moving the screen to Utilities was therefore necessary rather than merely tidier, and enrolment
  behind plugin settings would have been unreachable on hardened production sites.

## 1.4.1

- An icon, in the same family as the other Coysh Digital Craft plugins: one site reporting outbound to
  one platform, in the red Manager uses.
- Full documentation in `docs/`, covering pairing, exactly what is and is not sent, what each capability
  permits, how backups work, the security model, every console command, and troubleshooting.

## 1.4.0

- The connector's screen moved from plugin settings to **Utilities**. Not for reachability — a plugin
  settings page stays linked and reachable with `allowAdminChanges` off, which was checked rather than
  assumed. It moved because of what the screen is: Settings holds things that are set, Utilities holds
  things you do, alongside Craft's own Updates, Caches and Database Backup.
- Pairing now needs the `utility:manager-connector` permission rather than Craft administrator, so an
  owner can let the person who looks after a site connect it without making them an administrator.
  That permission carries real authority and the screen says so.
- This plugin now registers no URL rules at all. Craft routes the utility, and the two actions go
  through Craft's action mechanism — POST with a CSRF token — so nothing it registers can be reached
  by following a link.

## 1.3.0

- Pair and disconnect from the control panel. The console still works, but requiring SSH excluded
  every site on managed hosting without a shell, which is an exclusion rather than an inconvenience.
- Fixed a redirect loop on the plugin settings screen. It redirected to itself, so the page could not
  be opened at all.
- Refuses a platform URL that is not HTTPS. The setting previously supplied `https` as a *default*
  scheme, which silently accepts an explicit `http://` — and a signed request over plain HTTP is
  still readable in transit, including the enrolment code used to pair.
- When `platformUrl` is set in `config/manager-connector.php`, the control panel cannot override it.
  On a site configured that way, a hijacked session can only pair with the platform already chosen.

## 1.2.0

- Runs `backup.create` jobs. The database is dumped through Craft's own backup, using the connection
  the site already has, then encrypted here with a key generated on this server and sealed to the
  platform. The plaintext dump is deleted whether the upload succeeds or not.
- Backups are uploaded to the platform this site paired with and nowhere else. The job carries no
  destination and there is no argument anywhere in the upload path that could supply one, so a
  compromised platform can ask for a backup but cannot ask for one to be sent elsewhere.
- Refuses to back up at all if the platform has published no encryption key. A database never travels
  in the clear.
- Learns the platform's artifact encryption key from signature-verified responses only, so a rotation
  is followed automatically and an intercepted response cannot substitute a key.
- `uploadTimeout` and `maxBackupMegabytes` settings.

## 1.1.0

- Reports available Craft and plugin updates, including whether any release in between is flagged
  critical. Release notes are deliberately not sent: they describe what a version fixes, and
  forwarding them would put a description of an unpatched vulnerability, attached to your site, into
  a dashboard.
- Claims and runs jobs from the platform's registry, refusing any type this version does not
  implement.
- `manager-connector/updates` and `manager-connector/jobs`.

## 1.0.0

Initial release.

- Pairs with a Manager platform using a single-use enrolment code.
- Generates its Ed25519 keypair locally; the private key never leaves the installation.
- Signs every outbound request, and verifies the platform's signature on the pairing response.
- Reports operational metadata against the shared `inventory.v1` allowlist.
- Exposes no inbound management endpoint.

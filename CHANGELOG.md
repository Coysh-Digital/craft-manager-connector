# Changelog

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

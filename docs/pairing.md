# Pairing

Pairing is how a Craft site and a Manager installation come to trust each other. It happens once, it
uses a code that works once, and it leaves the site holding a key that never leaves the server.

## What actually happens

1. In Manager, somebody adds the site and issues an **enrolment code**. Manager stores only a SHA-256
   hash of it - there is no screen, route or column that will show the code again.
2. You paste the code into this plugin, in the control panel or on the console.
3. The plugin generates an Ed25519 keypair **on your server**.
4. It sends the code, the **public** half of the keypair, its version and this site's hostname to
   Manager. Over HTTPS, which is required - see below.
5. Manager verifies and consumes the code in one atomic step, records the public key against the site,
   and replies with the site's identifier, its own public key, and the capabilities it has granted.
6. That reply is **signed**, and the plugin verifies the signature before storing anything. A failure
   here is fatal to pairing rather than a warning: it is the plugin's first proof it is talking to the
   right server and not to whatever intercepted the request.

From then on every request the plugin makes is signed with the private half, which stays on your
server, encrypted with Craft's security key.

## From the control panel

**Utilities → Manager Connector**. Paste the code and press **Pair this site**.

If `platformUrl` is set in `config/manager-connector.php` the address is shown as fixed and cannot be
changed from this screen. If it is not set, you will be asked for it - check it carefully, because it
is where this site's operational state will be sent.

## From the console

```bash
php craft manager-connector/pair mgr_enrol_...
```

Or with an explicit address, if there is no configured one:

```bash
php craft manager-connector/pair --platformUrl=https://manager.example.org mgr_enrol_...
```

## HTTPS is required

The plugin refuses a platform address that is not HTTPS, and there is no option to override it.

A signature makes a request tamper-evident; it does not make it unreadable. Over plain HTTP the
enrolment code is legible to anything on the path - and it is a bearer secret until it is consumed —
as is every report that follows. ddev and every usable tunnelling service provide real certificates,
so an override would only enable the mistake.

## Codes expire, and work once

An enrolment code:

- is single-use, and consumed atomically, so two simultaneous attempts cannot both succeed
- expires, by default after fifteen minutes
- is invalidated when a newer one is issued for the same site

That last one matters: if you reissue a code because you think the first one leaked, the first one
stops working. Two live codes for one site would defeat the point of reissuing.

If a code has expired or been used, issue another in Manager. Nothing is lost by doing so.

## If the domain does not match

Manager records the domain it expects a site to be served from, and compares it against the hostname
the plugin reports when pairing.

If they differ, pairing **succeeds but is held**: the connector is stored in a
`pending_confirmation` state and nothing is reported until somebody approves it in Manager, on the
site's page, where both domains are shown side by side.

This is deliberate rather than a failure. Sites legitimately answer on more than one hostname, and a
staging copy pointed at the production platform is a mistake worth catching before it starts
reporting. What it prevents is a site quietly enrolling as a site it is not.

## Re-pairing a site that already has a connector

A working connector is not replaced silently. When you issue a code for a site that already has one,
Manager asks you to confirm that the new code may replace it, and records that authorisation **on the
code**.

Without that, a code cannot displace an active connector however it is used. That is what stops a
compromised site from re-pairing itself and being handed a fresh identity.

You would re-pair when:

- the site's signing key may have been exposed, and you want it invalidated
- you have moved the site to different infrastructure
- you disconnected it and want it back

## Disconnecting

```bash
php craft manager-connector/disconnect
```

Or the **Disconnect** button on the utility screen.

It deletes the signing key from this site, so the credentials stop working immediately. **Revoke the
connector in Manager too** - the platform has no way to know a site has disconnected itself, and will
go on expecting reports until it notices the silence.

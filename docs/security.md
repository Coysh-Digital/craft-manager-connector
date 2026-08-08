# Security

This plugin runs inside somebody's production website, which is the whole reason it is shaped the way it
is. This page is the threat model and what you can verify yourself.

Security issues go privately to **support@managerforcraft.com**, not to a public issue. See
[SECURITY.md](https://github.com/Coysh-Digital/craft-manager-connector/blob/main/SECURITY.md).

## What it holds, and what it does not

**Holds:** an Ed25519 signing key, generated here, with the private half encrypted using Craft's own
security key. The platform's public keys. The site's identifier and capability list.

**Does not hold:** a Craft administrator password. An SSH credential. Your database password. There is
nowhere in this plugin's schema to put any of them, and the platform has nowhere either.

A stolen copy of the platform's database confers no ability to impersonate any site, because only public
keys are stored there.

## Nothing inbound that can carry an instruction

The plugin registers **no URL rules at all**. Its one screen is a Craft utility, which Craft routes, and
its pairing actions go through Craft's action mechanism - POST with a CSRF token, behind an
authenticated control-panel session holding the utility permission.

There is exactly one endpoint that answers without a session, and **its entire vocabulary is "poll"**.
`POST actions/manager-connector/nudge/poll` asks this site to check in now. It reads no parameters,
refuses a request that arrives with a body, and does one thing: pushes the same task this site already
runs on its own timer. What happens next is the ordinary signed exchange that has always happened - this
site calls the platform, asks what is waiting, and decides for itself what to do with the answer.

So the endpoint changes *when* a check-in happens and nothing about *what* one may do. Job types are
still refused unless this plugin implements them, recovery keys are still refused unless this site has
pinned them, and the upload address is still derived here rather than accepted from anywhere. The worst
a forged, replayed or misdirected nudge achieves is an early poll.

To be answered at all, a nudge must carry an Ed25519 signature over a canonical string naming this site,
a timestamp and a nonce - verified against the platform public key this site pinned when it paired,
never against a key from the request. The timestamp must be within two minutes and the nonce is
single-use. Every refusal is an empty `401`, whatever the reason, so the endpoint cannot be used to
learn whether this site is paired or which check failed.

**A site behind NAT or a strict firewall still needs no inbound rule.** Nothing depends on a nudge
arriving. If the platform cannot reach this site - NAT, an IP allowlist, a WAF, HTTP basic auth - the
nudge simply never lands and the site keeps its own schedule, exactly as it did before this existed.
The cost is a few minutes of latency on a requested backup, and nothing else.

Set `'acceptNudges' => false` in `config/manager-connector.php` to refuse them outright. It is
config-file only: turning it off is harmless, and turning it on from a hijacked control-panel session is
not.

## Nothing to execute

There is no console-command runner, no PHP evaluation, no SQL execution, no file reading or writing, and
no arbitrary HTTP requests. Not gated - absent.

The plugin implements a fixed set of named operations and refuses any job type it does not already
implement, whatever the platform says. That refusal is the plugin's own, independent of the platform's
own refusal to issue an unknown type: two checks, because they protect against different failures. The
platform's stops a mistake; this one stops a compromised or impersonated platform.

Dispatch is a `match` over constants. There is no method name derived from a payload and no callable
resolved from a string, because validation around a variable method name is harder to be sure of than not
having one.

## Every request is signed

Each request carries the site identifier, this plugin's version, a timestamp, a random nonce, the HTTP
method, the canonical path and a hash of the body - all covered by an Ed25519 signature.

The platform verifies the signature, rejects a timestamp outside a short tolerance, rejects a nonce it
has seen before, and bounds the payload size. If its replay store is unreachable it **rejects** rather
than accepting, because accepting would mean accepting replays.

Responses that carry instructions or security-sensitive configuration are signed by the platform, and
this plugin verifies them against the key it learned at pairing. A missing signature where one is
expected is treated exactly like an invalid one. Without that check, whatever sits between this site and
the platform could hand it a job to run or a capability set to adopt.

TLS is required on top of all of this, and enforced: the plugin refuses a platform address that is not
HTTPS. Signing makes a request tamper-evident, not unreadable.

## What an attacker gains from each position

**A hijacked control-panel session with the utility permission.** They can pair this site to a Manager
platform they control, and from that platform grant themselves backup permission and collect the
database. This is the real risk of having a pairing screen at all.

Mitigation: set `platformUrl` in `config/manager-connector.php`, creating that file if the site has
none - installing the plugin does not create it, so by default this mitigation is not in place. When
it is set the screen cannot override it, so the site can only ever pair with the platform you chose,
and changing that takes a deployment. On a site with `allowAdminChanges` on, note that an administrator can already install
arbitrary plugins, which is unrestricted code execution - so on such a site this route adds nothing they
did not have.

**A compromised platform.** It can ask for the reports the site's capabilities already permit, and can
ask for a backup if that capability was granted. It cannot make the site execute anything, and it cannot
redirect a backup elsewhere: the destination is not a parameter.

**Someone on the network path.** TLS is required, so they see nothing. If they strip it, the plugin
refuses to talk. If they tamper with a response carrying instructions, signature verification fails and
the response is discarded.

**A stolen copy of this site's database.** The signing key is encrypted with Craft's security key, which
lives in the environment rather than the database. A database dump alone does not let anybody sign as
this site.

## Verify it yourself

The source is published so it can be read before it is trusted. Two things worth running:

```bash
# Exactly what this site would report, without sending it
php craft manager-connector/preview

# Structural checks on this plugin's own source
php bin/verify-invariants.php
```

The second one is the interesting one. It reads the source and fails if the plugin gains a site route, a
controller that does not require its permission, an action that changes state without POST, a means of
execution, an unreviewed dependency, a way to transmit the private key, a backup destination that could
come from a parameter, or a version that disagrees with itself. It needs no dependencies and no Craft
install, so it runs in about a second - which is the point, because a check that is slow is a check that
gets skipped.

## Verifying a release

Tags are no longer signed, and there is no published signer list to check one against. Installing
through Composer, Composer verifies the package hash against Packagist for you, which establishes
that you received what Packagist holds. It does not establish who published it.

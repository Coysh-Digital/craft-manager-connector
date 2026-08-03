# Backups

If, and only if, you grant `backups:create`, Manager can ask this site for a database backup. This page
is what happens on the site's side of that.

## Before anything can happen

Four things, all of which must be true:

1. The site has been granted `backups:create` in Manager. Never at pairing, and never with a switch —
   see [Capabilities](/capabilities).
2. Your organisation has at least one **active recovery key**. That is a keypair you generate on your
   own machine; Manager holds the public half and has nowhere to put the other one.
3. You have pinned that key's fingerprint in `config/manager-connector.php` — creating that file if the
   site does not have one, which most do not, because nothing creates it for you. Strictly speaking a
   backup will still run without this, but read [The step people skip](#the-step-people-skip) before
   deciding not to.
4. Manager has queued a `backup.create` job, either on a schedule or because somebody pressed a button.

## What the plugin does

1. **Checks which keys it is being asked to encrypt to, before anything else.** The keys arrive on a
   signature-verified response. Every one is checked against the fingerprints pinned in your own
   configuration, and a single unrecognised key fails the whole backup.
2. **Dumps the database using Craft's own backup.** `Craft::$app->getDb()->backup()`, which uses the
   connection and credentials the site already has. There is no `mysqldump` command composed here, no
   shell, and nothing Manager could influence about how the dump is taken. Manager asked for a backup;
   it did not say how to produce one.
3. **Generates a fresh encryption key**, for this artifact and no other.
4. **Encrypts the dump** as a chunked authenticated stream, so nothing large is ever held in memory and
   a modified or truncated artifact is detected as such rather than read as a shorter backup.
5. **Seals that key once per recovery key.** This plugin holds only public halves, so it cannot reopen
   what it sealed — a site compromised in September cannot recover the keys to artifacts it uploaded in
   June.
6. **Writes a manifest and signs it** with this site's own signing key, then wraps it around the
   encrypted stream. The result is a self-describing file: everything needed to decrypt it is inside
   it, so it can be opened years later with a private key and nothing else.
7. **Declares the artifact**: sizes, checksums, the manifest and its signature. Metadata only.
8. **Uploads the bytes**, streamed, with the content hash covered by the request signature.
9. **Deletes every temporary file**, whether the upload succeeded or not. While the plaintext dump
   exists it is the most dangerous file on the server, so it exists for as short a time as possible.

Step 1 comes first for a reason that is easy to lose in a refactor: a refusal that happens after step 2
has already written a complete copy of your database to disk.

## Encryption, stated plainly

**Manager cannot read your backups.** The key that opens an artifact is sealed only to your
organisation's recovery keys, and Manager has never held the other half of one — there is no column in
its database for a recovery private key, no escrow copy, and no support process that could produce one.
Somebody who stole Manager's database *and* its object storage would have ciphertext, wrapped keys they
cannot open, and metadata.

That is a much stronger claim than this page used to make, so here is exactly where it stops.

**It depends on you pinning fingerprints.** Manager tells this site which keys to encrypt to, because
the site has no other way to learn them. A Manager installation that had been compromised — or that was
compelled — could name a key of its own, and without pins this site would encrypt to it. Nothing would
look wrong. See below.

**It does not protect a compromised Craft site.** This server holds the database. Anybody with code
execution here can read it directly, and does not need a backup to do so. What a compromised site
*cannot* do is read backups it took before it was compromised, because it never held a key it could
reopen.

**It does not protect against losing your recovery key.** If you lose it, every backup encrypted to it
is permanently unreadable. Manager cannot help. Nobody can. That is not a limitation of the
implementation, it is what the guarantee costs.

**Artifacts taken before recovery keys existed are not covered.** Those were sealed to Manager's own
key and Manager can read them. Adding a recovery key does not change that retroactively, and Manager's
own screen labels them.

## The step people skip

```php
// config/manager-connector.php — create it if this site has none
'recoveryKeyFingerprints' => [
    'MGRK-4F3A-9C2B-7D18-E605-2A9F-33C1',
],
```

Get the fingerprint from `manager-restore fingerprint recovery-key.pub` — the file on your machine, not
the value on Manager's screen. Comparing Manager's screen against Manager's own claim proves nothing;
it can display whatever it likes.

This file is on your server, in your version control, and Manager cannot reach it. That is the entire
idea, and it is why the setting is **not** editable from the control panel: a hijacked control-panel
session that could re-pin this site would defeat the only control that actually works.

Once your fleet is pinned, set `requirePinnedRecoveryKeys => true` so an unpinned site refuses outright
rather than falling back.

If a backup fails with *"the platform offered recovery key MGRK-…, which this site has not pinned"*,
that is either a key you enrolled and forgot to pin, or a key you have never seen. Those need very
different responses, which is why the message names it.

## Size limits

Two of them:

- `maxBackupMegabytes` in this plugin's settings, default 2048. A safety valve rather than a policy:
  dumping a database far larger than expected is how a backup job fills a production disk, and failing
  early with a clear message beats failing late with a full volume.

  **Self-hosted only. Manager Cloud ignores it.** A platform that stores and meters the artifact may
  lift this limit, and Cloud does — the storage is its own, already metered and billed, so a database
  that has grown past a number nobody chose is a thing to charge for rather than refuse. This file
  lives on your server and most sites have no config file at all, so the default was acting as a
  ceiling that had never been picked. Reporting to your own Manager, the reasoning reverses and the
  setting stands: whoever sets it also owns the disk the dump lands on.
- The platform's own ceiling, which it applies before reading the upload. From connector 1.11.0 the
  platform advertises that number on the signed claim response, so a database larger than it will fail
  **before** the dump is taken rather than after it has been dumped, encrypted and offered. The
  message names both sizes.

There is no longer a third limit. Until `manager-protocol` 1.5.0 the wire contract itself refused any
artifact over 2 GB, which is not something an operator or a hosted platform could raise, and it was
refusing real backups on sites whose databases had simply grown.

## Free disk

A backup needs roughly **twice the dump** on disk at its peak: the dump itself, then the encrypted
stream and the assembled artifact alongside each other while the envelope is written. The plugin
checks before it starts, sized against the last dump it took, and refuses with both numbers in the
message rather than filling the volume.

The plaintext dump is removed as soon as encryption finishes rather than at the end of the upload, so
it exists for minutes rather than hours and does not occupy the disk while a large artifact uploads.

On a first run there is no history to size against, so the check passes and the ordinary failure path
stands.

## The destination cannot be changed

The `backup.create` job carries **nothing naming where the artifact should go**. It may carry one
thing: `max_megabytes`, a size ceiling, sent only by a platform that stores and meters the result.

That distinction is the point rather than a hedge. A size can only permit less work than this site
would otherwise do, or lift a limit on a platform that has taken responsibility for holding the
result. It cannot redirect a backup or widen who is able to read one, which is what the two
restrictions below are protecting.

The upload goes to the platform URL stored at pairing. When Manager offers a direct-to-storage upload
it supplies a path and a query string and **never a host**. The URL is built from one of exactly two
things, both of which you typed:

- `backupUploadHost` in your own configuration, if you set it; or
- `uploads.` in front of the platform host from `platformUrl` — the address you entered at pairing.

So no host Manager sent is used, even as an input to a comparison, and not at pairing either. A
platform compromised after you paired with it can vary the path inside a bucket you already reached
and can do nothing else. A script in this repository fails the build if a third source ever appears,
or if the derivation is given any argument other than the platform URL.

If nothing answers at that name, the upload falls back to going through Manager, which is what every
site did before this existed. Nothing is lost — the artifact is already encrypted by then.

The recipient list works the same way and for the same reason: Manager may name keys, but only ones you
have already pinned.

## What Manager is never told

The declaration is metadata. It contains no database credentials, no connection string, no table names,
no row counts, no schema, and no sample of the contents. The progress report carries a job identifier,
one word from a fixed list, and a timestamp — deliberately not a path, a byte count or the table
currently being written, all of which describe your data under a heading that looks harmless.

## Restoring

Manager will not restore a backup into a site, and this plugin has no code that could.

Restoring a production database is destructive and irreversible. Doing it safely needs a threat model
for a compromised platform issuing a restore, a confirmation flow that makes the target unmistakable, a
defined behaviour when a restore fails half way, and a tested recovery path from that state. None of
that follows from being able to take a backup.

To restore, download the artifact from Manager and open it yourself:

```bash
manager-restore inspect ./01JZX….artifact                      # what is it, whose keys open it
manager-restore verify  --key=recovery-key.secret ./01JZX….artifact
manager-restore decrypt --key=recovery-key.secret --out=./dump.sql ./01JZX….artifact
```

Then load `dump.sql` with `mysql` or `psql` on a host you have chosen deliberately.

`manager-restore` never contacts Manager. If Manager has been gone for a year and all you have is the
file and the key, it still works.

**A backup you have not restored is a hypothesis.** Decrypting proves the file is intact and yours; it
does not prove the SQL inside it loads into a working database. Nothing in this system can prove that
except loading it into one. Do that on a scratch host, on a schedule, and write down when you last did.

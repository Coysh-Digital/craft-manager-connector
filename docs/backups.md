# Backups

If, and only if, you grant `backups:create`, Manager can ask this site for a database backup. This page
is what happens on the site's side of that.

## Before anything can happen

Three things, all of which must be true:

1. The site has been granted `backups:create` in Manager. Never at pairing, and never with a switch —
   see [Capabilities](/capabilities).
2. The Manager platform has an artifact encryption key configured. Without one this plugin refuses
   outright rather than uploading a database unencrypted.
3. Manager has queued a `backup.create` job, either on a schedule or because somebody pressed a button.

## What the plugin does

1. **Dumps the database using Craft's own backup.** `Craft::$app->getDb()->backup()`, which uses the
   connection and credentials the site already has. There is no `mysqldump` command composed here, no
   shell, and nothing Manager could influence about how the dump is taken. Manager asked for a backup;
   it did not say how to produce one.
2. **Generates a fresh encryption key**, for this artifact and no other.
3. **Encrypts the dump** as a chunked authenticated stream, so nothing large is ever held in memory and
   a modified or truncated artifact is detected as such rather than read as a shorter backup.
4. **Seals the key** to the platform's public encryption key. This plugin holds only the public half,
   so it cannot reopen what it sealed — a site compromised in September cannot recover the keys to
   artifacts it uploaded in June.
5. **Declares the artifact**: sizes, checksums, and the sealed key. Metadata only.
6. **Uploads the bytes** to the platform, streamed, with the content hash covered by the request
   signature.
7. **Deletes both temporary files**, whether the upload succeeded or not. While the plaintext dump
   exists it is the most dangerous file on the server, so it exists for as short a time as possible.

## The destination cannot be changed

The `backup.create` job carries **no parameters at all**. In particular it carries nothing naming where
the artifact should go.

This is the single most important property of the feature. A parameter naming a destination would let a
compromised platform tell every site it manages to send its database somewhere else. Instead the upload
goes to the platform URL stored at pairing, and there is no argument, payload field or setting anywhere
in the path that could replace it. A script in this repository fails the build if somebody adds one.

## Encryption, stated plainly

Artifacts are encrypted on this server before they leave it, which protects them in transit and at rest
in the platform's storage. A stolen backup store yields nothing on its own.

**It is not end-to-end encryption.** The platform holds the key that opens them — it has to, or nobody
could ever restore one. Anyone with the platform's backup secret key and access to its storage can read
every backup it holds.

What that means in practice: treat the Manager installation as being as sensitive as the sites it
manages, because in effect it is.

## Size limits

Two of them:

- `maxBackupMegabytes` in this plugin's settings, default 2048. A safety valve rather than a policy:
  dumping a database far larger than expected is how a backup job fills a production disk, and failing
  early with a clear message beats failing late with a full volume.
- The platform's own ceiling, which it applies before reading the upload.

## What Manager is never told

The declaration is metadata. It contains no database credentials, no connection string, no table names,
no row counts, no schema, and no sample of the contents. The platform needs enough to store the bytes,
verify they arrived intact, and eventually decrypt them. Everything beyond that would be collection for
its own sake.

## Restoring

Manager will not restore a backup into a site, and this plugin has no code that could.

Restoring a production database is destructive and irreversible. Doing it safely needs a threat model
for a compromised platform issuing a restore, a confirmation flow that makes the target unmistakable, a
defined behaviour when a restore fails half way, and a tested recovery path from that state. None of
that follows from being able to take a backup.

To restore, retrieve the artifact from Manager — `php artisan manager:backups:fetch`, which decrypts it
and verifies it against the checksum taken here — and load it with `mysql` or `psql` on a host you have
chosen deliberately.

---
layout: home

hero:
  name: Manager Connector
  text: Fleet monitoring for Craft, without the keys
  tagline: Reports what a Craft installation is running so it can be monitored from one place, without an administrator password, an SSH credential or a database password ever leaving the server.
  actions:
    - theme: brand
      text: Get started
      link: /installation
    - theme: alt
      text: What is sent
      link: /what-is-sent

features:
  - title: Outbound only
    details: This plugin opens no inbound endpoint. Every exchange starts here and goes out, so a site behind NAT or a firewall works with no inbound rule at all, and there is nothing listening to attack.
  - title: No credentials to lose
    details: Manager never holds an administrator password, an SSH key or a database password. The signing key is generated on your server and only its public half is ever sent.
  - title: You decide what it may do
    details: A newly paired site can read its own version numbers and nothing else. Everything beyond that is granted per site, and taking a backup needs its own confirmation.
  - title: Nothing to execute
    details: There is no console runner, no PHP evaluation, no SQL and no file access. The plugin performs a fixed set of named operations and refuses anything it does not already implement.
---

## What it is

Manager Connector is the part of [Manager](https://coysh.digital/manager) that lives inside a Craft
installation. It answers questions like "what version is this running", "is it patched", "are its
licences valid" and "did the backup run" — so you can see the answers for every site you look after
on one screen, rather than logging into ten control panels.

It is deliberately small. It runs inside somebody's production website, which is the strongest
argument there is for doing as little as possible.

## What it does not do

Worth stating before anything else, because it is the reason the plugin is shaped the way it is:

- It never asks for a Craft administrator password.
- It never asks for SSH credentials.
- It never stores your database password.
- It opens no endpoint that the platform, or anybody else, can call into.
- It cannot run a console command, evaluate PHP, run a query or read a file, on instruction or
  otherwise. The code to do those things is not present, and a script in this repository fails the
  build if it appears.

## How it fits together

```
Craft site                             Manager
──────────                             ───────
Manager Connector  ──── outbound ────▶  API
  signing key                            site record
  (private half                          public key only
   never leaves)                          capability grants
```

Pairing exchanges a single-use code for an identity. From then on every request is signed with a key
that was generated on your server, and the platform verifies the signature before it will accept
anything.

## Where to go next

| | |
|---|---|
| [Installation](/installation) | Composer, then pairing |
| [Pairing](/pairing) | Enrolment codes, domain confirmation, re-pairing |
| [What is sent](/what-is-sent) | Field by field, and what is refused |
| [Capabilities](/capabilities) | What each permission actually permits |
| [Backups](/backups) | How a backup is taken, encrypted and uploaded |
| [Security](/security) | The threat model, and what to check yourself |
| [Console commands](/console-commands) | Every command, and what to schedule |
| [Troubleshooting](/troubleshooting) | When something is not reporting |

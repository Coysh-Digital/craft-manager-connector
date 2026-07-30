# Security policy

## Reporting a vulnerability

Email **hello@coysh.digital**. Please do not open a public issue.

Include the connector version, the Craft version, and enough detail to reproduce. If you have a
proof of concept, say so rather than attaching it in the clear.

We aim to acknowledge within two working days, and to keep you informed while a fix is prepared.
We will credit you when the advisory is published unless you would rather we did not.

## Supported versions

The current minor release receives security fixes. Older minors receive them for 90 days after
being superseded.

## Coordinated disclosure

We publish an advisory once a fix is available, or after 90 days, whichever comes first. If a
vulnerability is being exploited we will move faster and say so.

## Emergency revocation

If you believe this site's signing key has been exposed:

```bash
php craft manager-connector/disconnect
```

That deletes the key from the site immediately. Then revoke the connector in Manager, which stops
the platform accepting anything signed with it. Both steps matter: the first stops the key being
used from here, the second stops it being used from anywhere else.

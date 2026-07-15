# Proposal: an admin-delegated learner session for embedding Kolibri

*Status: request for comment, addressed to Learning Equality. Written from the
kubo-kolibri bridge, but the primitive it asks for is not KUBO-specific.*

## Summary

We would like a supported way for a trusted server-side integration, holding
facility-admin credentials, to obtain a scoped Kolibri **session for a specific
facility user** without knowing or setting that user's password. One endpoint —
"as this admin, mint a session for facility user `X`" — would let external tools
embed Kolibri content on behalf of a signed-in learner cleanly, and would let us
delete a password-derivation workaround we are not happy shipping.

## Context: what we are building

KUBO is an offline-first school management platform. **kubo-kolibri** is a bridge
that maps a school's curriculum onto Kolibri content nodes and serves Kolibri's
exercises and videos *inside* KUBO's own UI, so a pupil never has to learn or log
into a second tool. KUBO and Kolibri run on the same machine (a classroom
Raspberry Pi, or a single server), Kolibri bound to localhost.

A pupil is already authenticated in KUBO. When they open an exercise, KUBO needs
the browser to carry a **Kolibri learner session** for *that* pupil, so Kolibri
renders and logs progress against the right account.

## The problem

Kolibri authenticates a session with `POST /api/auth/session/` using a
username + password. There is no admin-facing way we could find to say "I am the
facility superuser; issue a session for facility user `X`." So to establish a
learner's session server-side, the integration must **know that learner's
password**.

## What we do today (the workaround we want to remove)

At provisioning time we set each learner's Kolibri password to a value derived
from a single shared secret and the KUBO user id:

```
password = substr(sha256(KOLIBRI_LEARNER_SECRET . user_id), 0, 16)
```

Then, when a pupil opens an exercise, KUBO re-derives that password server-side,
calls `POST /api/auth/session/` as the learner, takes the resulting Kolibri
session cookie, and hands **only that cookie** to the browser, scoped (HttpOnly)
to the reverse-proxy path the pupil's exercises load through. The pupil never
sees or types a Kolibri password.

This works, and we have hardened it (learner-only privileges, cookie isolation,
an SSRF-guarded proxy). But the shared secret is a real weakness: if
`KOLIBRI_LEARNER_SECRET` leaks, **every** learner's password is derivable. It
also means we control learner passwords, which is not something an embedding tool
should have to do.

## What would make this clean

A single admin-authenticated endpoint that mints a session for a named facility
user. Sketch, not a spec:

```
POST /api/auth/delegated-session/
Authorization: <facility-admin session>
{ "user": "<facility_user_id>" }

201 Set-Cookie: kolibri=<session>; HttpOnly; ...
{ "user_id": "...", "facility": "...", "roles": ["learner"] }
```

Properties that would matter to us:

- **Admin-gated.** Only a session with an appropriate facility role (e.g.
  superuser / admin) may call it, and only for users within its own facility.
- **No password knowledge.** The caller never needs, sets, or derives the
  learner's password. Password management stays entirely inside Kolibri.
- **Scoped and short-lived is fine.** A session good only for content playback
  and progress logging, expiring quickly, is exactly what an embed needs.
- **Auditable.** The mint is an admin action Kolibri can log.

With this, the bridge drops password derivation entirely: KUBO already holds
admin credentials for provisioning, so it would ask Kolibri for a learner session
directly, per pupil, at exercise time.

## Two smaller asks that would compound

1. **A documented embed / iframe contract.** We currently render Kolibri content
   through a same-origin reverse proxy and inject a little CSS/JS to fit it into
   KUBO's chrome. A supported "embedded content" mode with a stable
   `postMessage` progress contract (started / answered / mastered) would let us
   drop the HTML rewriting and read progress events directly.

2. **Progress reads keyed by content, for a set of learners.** We already read
   `contentsummarylog` / `attemptlog` / `masterylog` per user. A batch read
   ("mastery for these learners on these content nodes") would make classroom
   progress sync cheaper at scale.

## Why this is worth it beyond KUBO

Any LMS or SIS that wants to present Kolibri content to already-authenticated
learners hits this same wall and, we suspect, works around it the same way.
A first-class admin-delegated session turns "embed Kolibri behind our own login"
from a per-integration hack into a supported pattern — which is squarely the
kind of adoption Kolibri's API surface is meant to enable.

## Origin

This continues work started in 2017 at The Swallow school in The Gambia, mapping
KA Lite content to the Gambian mathematics curriculum. The bridge is the piece
that was always missing; this endpoint is the piece the bridge is missing.

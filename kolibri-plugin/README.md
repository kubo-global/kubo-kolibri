# kolibri-delegated-session

A small Kolibri plugin with one endpoint: a signed-in facility admin asks Kolibri
for a session that belongs to a learner of their facility.

It is meant for server-side integrations that show Kolibri content behind their
own login, such as a school management system that already knows which pupil is
at the screen. Without it, the only way to a learner session is the learner's
password, so the integration has to know or set that password. With it, password
management stays inside Kolibri.

Nothing in the plugin is specific to KUBO.

## Install

Install it into the Python environment Kolibri runs from, enable it, and restart
Kolibri:

```
pip install ./kolibri-plugin
kolibri plugin enable kolibri_delegated_session
```

Then set `KOLIBRI_DELEGATED_SESSION=true` in KUBO's `.env`.

## The endpoint

```
POST /delegated-session/api/session/
Cookie: kolibri=<admin session>; kolibri_csrftoken=<token>
X-CSRFToken: <token>

{ "user": "<facility user id>" }
```

```
201
{
  "user_id": "...",
  "facility": "...",
  "session": { "cookie": "kolibri", "value": "<session key>" },
  "csrf":    { "cookie": "kolibri_csrftoken", "token": "<token>" }
}
```

The caller places the two values as cookies in front of the learner's browser.
The response body carries a live session key: call this over localhost or TLS,
and do not log it.

## Rules

- The caller must be signed in as a device superuser, or as an admin of the
  facility the target user belongs to. Coaches and learners are refused.
- A session is only minted for a learner. A user with any role (coach, admin)
  or with superuser rights is refused, so the endpoint cannot be used to gain
  rights.
- The caller's own session is left as it was.
- Every minted session is written to Kolibri's log with the caller and the
  learner, and goes through Django's regular `login`, so the usual sign-in
  signals fire.

| Status | `id` | When |
|---|---|---|
| 403 | `NOT_AUTHENTICATED` | no signed-in user |
| 403 | `NOT_AN_ADMIN_OF_THIS_FACILITY` | caller is not an admin of the target's facility |
| 403 | `TARGET_IS_NOT_A_LEARNER` | target has a role or superuser rights |
| 404 | `USER_NOT_FOUND` | unknown or malformed user id |

## Limits

The minted session is an ordinary Kolibri learner session. It is not narrowed to
content playback and it lives as long as any other session. Kolibri has no
narrower session to offer today.

## Tests

Run them in a scratch `KOLIBRI_HOME`, not in the home of a live Kolibri: the test
setup clears Kolibri's on-disk cache.

```
KOLIBRI_HOME=/tmp/kolibri-test kolibri plugin enable kolibri_delegated_session
KOLIBRI_HOME=/tmp/kolibri-test kolibri manage test kolibri_delegated_session
```

Tested against Kolibri 0.19.5.

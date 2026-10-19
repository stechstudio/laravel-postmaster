# PostShiba

Postmaster supports PostShiba's webhooks, suppression sync, and the dashboard,
tracking, resend, and sandbox features. It needs no extra SDK.

**Status:** built from PostShiba's docs and tested with fixtures. It hasn't
been tested against live webhooks yet.

## Send

PostShiba's webhooks identify a message by its `smtp-id`, which is the
Message-ID PostShiba gives the email. Postmaster stores that Message-ID when
you send, so the two match.

This needs a version of the `postshiba/postshiba` transport that copies the
Message-ID from PostShiba's response onto the sent message.
With an older transport, webhooks arrive but match no message.

PostShiba doesn't return Laravel's `metadata()`. `$event->data()` holds the
event's `unique_args`.

## Webhooks

```dotenv
POSTMASTER_POSTSHIBA_SIGNING_SECRET=...
```

In PostShiba, open Webhooks and create an endpoint:

1. Set the URL to `https://your-app.example/webhooks/postmaster/postshiba`.
2. Select the events you want.
3. Copy the endpoint's signing secret into
   `POSTMASTER_POSTSHIBA_SIGNING_SECRET`.

Each endpoint has its own secret. For several endpoints, separate the secrets
with commas.

PostShiba signs `{timestamp}.{body}` with HMAC-SHA256 and sends the result in
`X-Capsule-Signature`, with the time in `X-Capsule-Timestamp`. Postmaster
rejects a request whose timestamp is more than five minutes from the server
clock, so keep the clock accurate. A rejected request gets HTTP 403, the same
as every other provider. PostShiba retries any response that isn't 2xx.

"Send test event" posts an event marked `"test": true`. Postmaster answers it
with a 2xx and records nothing.

PostShiba retries a failed delivery up to six times over about 35 hours. A
retried event still reaches your listeners, but the delivery history records
it only once.

The body is a SendGrid-style JSON array of events:

| PostShiba event | Postmaster status |
|---|---|
| `processed` | accepted |
| `delivered` | delivered |
| `deferred` | deferred |
| `bounce` with `type` `bounce` | bounced (hard) |
| `bounce` with `type` `blocked` | bounced (block) |
| `dropped` | dropped |
| `spamreport` | complained |
| `unsubscribe` | unsubscribed |

PostShiba sends no open or click events.

## Suppression sync

```dotenv
POSTMASTER_POSTSHIBA_API_KEY=...
POSTMASTER_POSTSHIBA_TEAM_ID=...
```

Sync needs a Full access API key. The Send only key your app sends with gets
HTTP 403 from the suppressions API. The team ID is the short ID in your
dashboard URLs.

PostShiba's docs name only the `manual` and `bounce` reasons, so Postmaster
matches reasons loosely:

| PostShiba reason contains | Postmaster reason |
|---|---|
| `bounce` | bounced |
| `spam` | complained |
| `unsub` | unsubscribed |
| anything else | manual |

To unsuppress an address, Postmaster calls PostShiba's bulk delete with that
address.

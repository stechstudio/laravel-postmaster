# PostShiba

Postmaster supports PostShiba's webhooks, suppression sync, and the dashboard,
tracking, resend, and sandbox features. It needs no extra SDK.

**Status:** tested against a live account on 2026-10-18. See
[Live test results](#live-test-results) for what was and wasn't covered.

## Send

PostShiba's webhooks identify a message by its `smtp-id`, which is the
Message-ID PostShiba gives the email. Postmaster stores that Message-ID when
you send, so the two match.

This needs a version of the `postshiba/postshiba` transport that copies the
Message-ID from PostShiba's response onto the sent message.
With an older transport, webhooks arrive but match no message.

PostShiba doesn't return Laravel's `metadata()`. `$event->data()` holds the
event's `unique_args`.

PostShiba checks its suppression list without the `+tag`, so suppressing
`jo@example.com` also skips `jo+news@example.com`. A skipped recipient gets no
webhook. Postmaster records it as dropped, with a reason such as "On the
PostShiba suppression list as jo@example.com". If PostShiba would skip every
recipient, it refuses the send and the transport throws
`PostShiba\Error: suppressed`. Turn on `POSTMASTER_BLOCK_SUPPRESSED` to block
those recipients before the send instead.

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

## Live test results

Tested on a transactional cluster through the `postshiba/postshiba` transport.
The sends covered To, Cc, and Bcc deliveries, a hard bounce, a null-MX domain,
a domain that doesn't exist, and suppressed recipients.

**Works as documented:**

- Webhooks are signed with `X-Capsule-Signature`, and "Send test event" is
  accepted without being recorded.
- `processed` arrives within seconds. `delivered` follows 20 to 60 seconds
  later, and a hard bounce about 15 seconds later.
- Each To, Cc, and Bcc recipient gets its own events, all with the same
  `smtp-id`. The `smtp-id` matches the message Postmaster recorded.
- `unique_args` come back on every event as `$event->data()`.
- A hard bounce suppresses the address locally, and PostShiba adds it to its
  own list with reason `bounce`.
- Sync reads a manual suppression, and unsuppress removes it with bulk delete.

**Differs from the docs:**

- PostShiba drops the `+tag` and lowercases addresses when it stores and
  checks suppressions. See [Send](#send).
- The bounce `response` is the receiving server's text without the `550 5.1.1`
  prefix. The enhanced code is in `status`, which `$event->code()` returns.
- `attempt` is a string, such as `"0"`.
- The unique args are also copied onto the top level of each event. No
  `category` field arrives, even when the message has tags.
- A null-MX domain sends `bounce` with `type` `blocked` and status `5.1.10`.
  Postmaster suppresses the address locally, like any block, but PostShiba
  doesn't. The next sync clears the local suppression.
- A domain that doesn't exist sends `deferred` with status `4.4.4`. One send
  produced two `deferred` events with the same timestamp; Postmaster recorded
  one.
- A send to an `.invalid` domain is refused with
  `PostShiba\Error: The cluster did not accept the message`. No message is
  recorded.

**Not tested:**

- `dropped`, `spamreport`, and `unsubscribe`. A suppressed recipient got no
  `dropped` event, and a transactional cluster adds no unsubscribe link.
- Soft bounces that later deliver, and the final bounce after a long deferral.
- Webhook retries after a failed delivery.

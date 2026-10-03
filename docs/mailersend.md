# MailerSend

Postmaster supports MailerSend's activity webhooks, suppression sync, and the
dashboard, tracking, resend, and sandbox features. It needs no MailerSend SDK.

**Status:** tested against a live trial account on 2026-10-03. See
[Live test results](#live-test-results) for what was and wasn't covered.

## Send

Postmaster records the message ID that MailerSend's webhooks carry for each of
the three ways to send:

| How you send | Where the ID comes from |
|---|---|
| [mailersend/laravel-driver](https://github.com/mailersend/mailersend-laravel-driver) (`MAIL_MAILER=mailersend`) | The `X-MailerSend-Message-Id` header the driver adds |
| Symfony's `mailersend+api` transport | The sent message's ID |
| SMTP through `smtp.mailersend.net` | The `250 Message queued as <id>` reply |

MailerSend accepts a send but skips any recipient on a suppression list, and
its webhooks may never mention that recipient. Postmaster then leaves the
recipient's row at "sent". If every recipient is suppressed, MailerSend
creates no message at all, so no webhook ever arrives.

MailerSend's API response lists the skipped recipients, but none of the three
ways to send passes that list on to Postmaster. The Laravel driver tries to,
in an `X-MailerSend-Body` header, but the header is always empty: MailerSend's
SDK reads the response body before the driver does
([mailersend-laravel-driver#95](https://github.com/mailersend/mailersend-laravel-driver/issues/95)).

To keep Postmaster from sending to a suppressed address at all, run suppression
sync and turn on `POSTMASTER_BLOCK_SUPPRESSED`.

The Laravel driver doesn't send Laravel's `tag()` or `metadata()`. Use its
`mailersend()` helper for tags. MailerSend's webhooks never return metadata, so
`$event->data()` is always empty.

## Webhooks

```dotenv
POSTMASTER_MAILERSEND_SIGNING_SECRET=...
```

In MailerSend, open Integrations → Webhooks and create a webhook:

1. Set the URL to `https://your-app.example/webhooks/postmaster/mailersend`.
2. Choose payload version 2. Postmaster also reads version 1.
3. Select the `activity.*` events you want. Pick either `activity.opened` or
   `activity.opened_unique`, not both, or a first open counts twice. The same
   goes for clicks.
4. Save, then copy the webhook's signing secret into
   `POSTMASTER_MAILERSEND_SIGNING_SECRET`.

Webhooks belong to one domain, and each has its own secret. For several
domains, separate the secrets with commas.

When you save the webhook, MailerSend sends a test request signed with a
published test secret. Postmaster accepts that secret for the test request
only, so you can save the webhook before you've set your own secret.

| MailerSend event | Postmaster status |
|---|---|
| `activity.sent` | accepted |
| `activity.delivered` | delivered |
| `activity.hard_bounced` | bounced (hard) |
| `activity.soft_bounced` | bounced (soft) |
| `activity.deferred` | deferred |
| `activity.opened`, `activity.opened_unique` | opened |
| `activity.clicked`, `activity.clicked_unique` | clicked |
| `activity.unsubscribed` | unsubscribed |
| `activity.spam_complaint` | complained |
| `activity.suppressed` | dropped |

Postmaster ignores other events, such as survey answers, maintenance notices,
and on-hold changes, without logging them as invalid.

MailerSend's signature has no timestamp, so Postmaster can't reject a replayed
request. A replayed event still reaches your listeners, but the delivery
history records it only once.

## Suppression sync

```dotenv
POSTMASTER_MAILERSEND_API_KEY=...
POSTMASTER_MAILERSEND_DOMAIN_ID=...   # optional
```

The API token needs full access to Suppressions. Without
`POSTMASTER_MAILERSEND_API_KEY`, sync uses the Laravel driver's
`MAILERSEND_API_KEY`. Set `POSTMASTER_MAILERSEND_DOMAIN_ID` to sync one domain;
leave it empty to sync the whole account.

| MailerSend list | Postmaster reason |
|---|---|
| Hard bounces | bounced |
| Spam complaints | complained |
| Unsubscribes | unsubscribed |
| Blocklist, single addresses | manual |

Sync skips blocklist patterns such as `.*@example.com`, because they aren't
single addresses. It also skips the on-hold list, because MailerSend releases
those recipients on its own after a few days.

MailerSend deletes suppressions by entry ID, not by address. To unsuppress an
address, Postmaster reads each list, finds the address, and deletes the
matching entries.

Each page of each list is one API request. A trial account allows 100 API
requests a day, so a large list can use up the trial's quota.

## Live test results

Tested on a trial account through both the Laravel driver and SMTP. Each
covered a To and Cc delivery and a hard bounce.

**Works as documented:**

- Webhooks put the address in `data.recipient`, sign with a `Signature`
  header, and arrive about 20 seconds after the event. `created_at` is the
  event time.
- Saving a webhook sends `webhook.test` signed with the published test
  secret. Postmaster's response lets the save finish.
- A Cc recipient gets its own `activity.sent` and `activity.delivered` events
  under the message's ID.
- The SMTP relay replies `250 Message queued as <id>`, and the ID matches the
  webhooks' `message_id`.
- A hard bounce suppresses the address locally, and sync finds it on
  MailerSend's hard-bounce list.
- Sync reads the blocklist as manual suppressions, and unsuppress removes the
  blocklist entry.
- Suppression lists page with `links.next`.

**Differs from the docs:**

- `bounce_code` is MailerSend's own code, such as `34`, not an SMTP reply
  code. `$event->code()` returns it as is. The reason is readable text, not
  the receiving server's reply.
- `tags` is `null`, not an empty list, when a message has no tags.
- Webhook payloads also carry a top-level `webhook_id`.
- The create-webhook response returns the signing secret as `secret`.
- A trial account allows only one To address per message.

**Not tested:**

- Opens and clicks. The trial domain doesn't allow tracking.
- Soft bounces, deferrals, unsubscribes, and spam complaints.
- Bcc recipients.
- `activity.suppressed`. The trial account sent none, even with a
  blocklisted Cc.
- Symfony's `mailersend+api` transport.
- Version 1 payloads, and which version a webhook gets when none is chosen.

# MailerSend

Postmaster supports MailerSend's activity webhooks, suppression sync, and the
dashboard, tracking, resend, and sandbox features. It needs no MailerSend SDK.

**Status:** built from MailerSend's documentation without a live account. The
tests use MailerSend's documented payloads. See
[Check against a live account](#check-against-a-live-account) before you rely
on it.

## Send

Postmaster records the message ID that MailerSend's webhooks carry for each of
the three ways to send:

| How you send | Where the ID comes from |
|---|---|
| [mailersend/laravel-driver](https://github.com/mailersend/mailersend-laravel-driver) (`MAIL_MAILER=mailersend`) | The `X-MailerSend-Message-Id` header the driver adds |
| Symfony's `mailersend+api` transport | The sent message's ID |
| SMTP through `smtp.mailersend.net` | The `250 Message queued as <id>` reply |

MailerSend accepts a send but skips any recipient on a suppression list. With
the Laravel driver, Postmaster marks those recipients as dropped right away,
using the response the driver keeps in the `X-MailerSend-Body` header. With
the other two, a dropped recipient shows up only through the
`activity.suppressed` webhook, which needs a paid plan.

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

## Check against a live account

These come from conflicting or missing documentation. Check each one with a
real account:

1. **Recipient field.** The webhook docs put the address in `data.recipient`;
   the setup guide uses `data.email`. Postmaster reads both.
2. **Signature header.** Every source says `Signature`, except the Node SDK's
   readme, which says `X-MailerSend-Signature`. Postmaster reads both.
3. **Default payload version.** Whether a webhook created without a version
   sends version 1 or 2.
4. **Suppression paging.** The docs show only `data`. The SDKs page with
   `links.next`. Without `links`, Postmaster keeps paging while pages are
   full.
5. **Copy and blind-copy recipients.** Whether each gets its own webhooks.
6. **The test request.** That saving a webhook sends `webhook.test` signed
   with the published secret, and that Postmaster's 200 lets the save finish.
7. **Send-time suppressions.** The shape of `X-MailerSend-Body` with the
   Laravel driver, for some and for all recipients suppressed.
8. **SMTP reply.** That the relay replies `250 Message queued as <id>` and
   that the ID matches the webhooks' `message_id`.

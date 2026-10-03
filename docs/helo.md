# Helo

Postmaster supports Helo's message and recipient webhook events, API
sending, suppression sync, and the existing dashboard, tracking, resend, and
sandbox features. It uses a small internal client built on Laravel HTTP;
there is no SDK to install. Domain verification events are outside this
package's email-delivery scope.

## Send through the API

Add a mailer to the `mailers` array in your app's `config/mail.php`:

```php
'helo' => [
    'transport' => 'helo',
],
```

Set these values in the app's `.env`:

```dotenv
MAIL_MAILER=helo
MAIL_FROM_ADDRESS=sender@your-verified-domain.com
MAIL_FROM_NAME="Your app"
HELO_API_KEY=...
HELO_CHANNEL_ID=...
POSTMASTER_HELO_SIGNING_KEY=...
POSTMASTER_HELO_MAIL_TYPE=transactional
```

`POSTMASTER_HELO_API_KEY` and `POSTMASTER_HELO_CHANNEL_ID` override the shorter
names. The API key needs access to the selected channel, sending, and
suppression operations. A channel-scoped key can send without a channel ID,
but suppression sync always needs the channel ID explicitly.

For several mailers, you can set `key`, `channel_id`, and `mail_type` directly
on each mailer. The provider-level settings still determine suppression
sync's scope. Use `broadcast` as the mail type for individual broadcast
messages; Postmaster uses Helo's `/send/broadcast/message` endpoint. It does
not manage bulk campaigns, domains, or channel provisioning.

Send ordinary Laravel Mailables and notifications. The transport preserves
To/Cc/Bcc, reply-to, HTML and text, attachments and inline images, custom
headers, and Laravel's `tags` and `metadata`. Helo's `messageId` becomes the
sent-message ID, so webhook events match the original records.

Helo applies channel tracking defaults. To override them for a message:

```php
$mail->withSymfonyMessage(function ($message) {
    $message->getHeaders()->addTextHeader('X-Helo-TrackOpens', 'true');
    $message->getHeaders()->addTextHeader('X-Helo-TrackLinks', 'false');
});
```

Set `X-Helo-Idempotency-Key` on a message when you need Helo to deduplicate
retries of the same submission. The transport forwards it as an HTTP header.
Helo rejects a reused key unless the request body is identical, and Postmaster
stamps every attempt with a new `Message-ID`, so the transport leaves
`Message-ID` out of keyed requests and Helo assigns one. It does not
automatically retry sends. HTTP failures and failed API results raise Symfony
transport exceptions, which Laravel's failover mailer handles. The exception
message includes Helo's error code and detail.

If Helo accepts a message but suppresses some recipients, those recipients
are recorded as `dropped`, and `EmailDropped` fires. Other recipients retain
their own delivery state. A `delayed` submission is recorded as `deferred`.
If every recipient is suppressed, Helo answers HTTP 422
`recipients_suppressed` and the send fails with a transport exception. With a
failover mailer, Laravel then tries the next mailer, so keep
`POSTMASTER_BLOCK_SUPPRESSED=true` and run sync if suppressed addresses must
not go out through another provider.

## Configure webhooks

Create a webhook in Helo for the channel this app uses, with this URL:

```
https://your-app.example/webhooks/postmaster/helo
```

Select these events:

| Helo event | Postmaster status |
| --- | --- |
| `message-accepted` | `accepted`, one event per recipient |
| `email-delivered` | `delivered` |
| `email-bounced` | `bounced` |
| `email-opened` | `opened` |
| `link-clicked` | `clicked` |
| `recipient-complained` | `complained` |
| `recipient-unsubscribed` | `unsubscribed` |
| `recipient-resubscribed` | `resubscribed` |

Postmaster ignores `message-processed`. Its own outbound record already
marks the message `sent`, so you can leave that event unselected.

Copy the webhook's signing key into `POSTMASTER_HELO_SIGNING_KEY`, then run
`php artisan config:clear`. Use the key exactly as provided, including any
prefix. Keep the server clock accurate. The signature timestamp must be
within five minutes; the event's own timestamp can be older on retries.

Hard bounces suppress an address; soft or unknown bounce types do not.
Helo's live events report `Hard` and `Soft`, while its API schema documents
`Permanent` and `Transient`; Postmaster accepts both. The receiving server's
diagnostic is stored as the event response, and its status (such as `5.1.1`)
as the code. Unsubscribes suppress the address and fire `EmailUnsubscribed`.
A current resubscribe clears an unsubscribe and fires `EmailResubscribed`.
Resubscribes do not clear manual suppressions or complaints, and delivery
does not override an unsubscribe. Both new statuses have event/model
predicates and model query scopes.

Helo receives HTTP 200 for accepted webhooks, including when
`POSTMASTER_QUEUE_WEBHOOKS=true`. Run a queue worker when you enable that
setting. Signature verification happens before queueing.

## Sync suppressions

```bash
php artisan postmaster:sync --provider=helo --dry-run
php artisan postmaster:sync --provider=helo
```

Helo scopes suppressions by **channel and mail type**. Postmaster's address
list is global. Configure sync for one channel and mail type, and keep the
webhook and sending configuration aligned. This is not a separate
suppression list for each tenant. If you send across independent Helo
channels or mix broadcast and transactional mail, disable local address
tracking/blocking and leave scoped suppression enforcement to Helo.

```dotenv
POSTMASTER_TRACK_ADDRESSES=false
POSTMASTER_BLOCK_SUPPRESSED=false
```

Do not enable Helo suppression sync in that arrangement: leave the
provider-level `channel_id` unset and configure the channel on each mailer.
Message delivery tracking still works across channels.

Sync maps Helo's `bounce`, `complaint`, `unsubscribe`, and `manual` reasons
to Postmaster's corresponding reasons. It fetches every page before changing
local data. A failed or malformed response cannot clear the local list.

Helo only permits API removal of bounce and manual suppressions. It can
reject an unsubscribe or complaint removal inside an HTTP 200 response;
Postmaster reports that as a provider failure. The existing
`Postmaster::unsuppress()` operation still lifts the local row and returns
Helo in its `manual` list so the operator knows Helo did not remove it.

## Use SMTP instead

Use Laravel's standard SMTP mailer with `smtp.helohq.com`, port 587, and a
Helo SMTP user's credentials. SMTP users are scoped to a channel. Webhook
verification and suppression sync use the same provider configuration above.
The install wizard detects Helo from the SMTP hostname.

The bundled API transport provides a documented message ID for correlation
and reports recipients suppressed during submission. Helo's SMTP queue-ID
format is not documented, so SMTP correlation needs confirmation against a
live account; use the API transport for the first end-to-end test.

## Run the first live test

Use a Laravel app with this branch installed and a publicly reachable HTTPS
webhook endpoint. The package's ordinary Workbench preview deliberately uses
sandbox/log delivery, so adding keys there alone will not send real mail.

1. Choose a Helo channel and verified sender domain. In Helo account test mode,
   the recipient must also use a verified domain.
2. Set the API key, channel ID, sender, and webhook signing key above. Use a
   normal delivery channel to test actual inbox delivery; a Helo sandbox
   channel simulates events.
3. Run `php artisan migrate`, then `php artisan config:clear`. Enable stored
   content/attachments if you want to test preview, resend, and release.
4. Register the public webhook URL and the eight events above in Helo.
5. Run:

   ```bash
   php artisan postmaster:verify --provider=helo --to=recipient@example.com
   ```

   Use a shared cache store such as `file`, `database`, or `redis`, so the CLI
   can see webhook events from the web process.
6. Check that a real delivery updates the original message row. Then test an
   attachment and an inline image, an open/click with tracking enabled, and a
   resend. Run suppression sync with `--dry-run` before changing local state.

Test bounces without hurting your reputation by sending to Helo's simulator:
`hard@bounce-test.helohq.com` (suppressed) or
`soft+mailboxfull@bounce-test.helohq.com` (not suppressed). Unsubscribe and
resubscribe links appear in broadcast mail. Gmail fetches the open pixel
through a proxy, so an open can arrive late or not at all.

The webhook fixtures in `tests/fixtures/helo` are redacted payloads captured
from the live API on 2026-10-03. That run covered sending, every event except
`recipient-complained`, suppression sync both ways, unsuppress, idempotent
retries, and signature rejection. Gmail does not report spam complaints back
to Helo, so complaint handling is tested only against Helo's documented
payload.

## Sources

- [Helo API specification](https://docs.helohq.com/openapi.json)
- [Sending transactional email](https://docs.helohq.com/core/sending-transactional)
- [Webhooks and retries](https://docs.helohq.com/core/webhooks)
- [Official webhook signature implementation](https://github.com/helo-email/helo-sdk-js/blob/main/src/utils/webhook-signatures.ts)
- [Suppression rules](https://docs.helohq.com/core/suppressions)
- [SMTP setup](https://docs.helohq.com/getting-started/quickstart-send-with-smtp)

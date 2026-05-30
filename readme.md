<img width="1500" height="676" alt="postmaster-gh" src="https://github.com/user-attachments/assets/f13e7990-61d2-4364-91ad-9a4a331b1350" />


# Postmaster

[![Latest Version on Packagist](https://img.shields.io/packagist/v/stechstudio/laravel-postmaster.svg?style=flat-square)](https://packagist.org/packages/stechstudio/laravel-postmaster)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE.md)

**Provider-agnostic email webhooks and delivery tracking for Laravel.**

Your app sends mail. Postmaster turns every webhook from SendGrid, Postmark,
Mailgun, Amazon SES, and Resend into one normalized event:

```php
use STS\Postmaster\EmailEvent;

Event::listen(function (EmailEvent $event) {
    if ($event->isBounced()) {
        // the address bounced; act on it
    }
});
```

Switch providers, run several at once, or fail over between them without
touching that code. Run the migrations and Postmaster also records every
outbound email and keeps it current as events arrive. You get a queryable
delivery history, a self-maintaining suppression list, and a dashboard to
browse it all.

## What you get

- **One event for every provider.** Every webhook arrives as the same
  `EmailEvent`, no matter which of the five providers sent it. There's no
  provider-specific parsing anywhere in your app.
- **Provider independence.** Your code only ever sees the normalized event, so
  you can switch providers, run several at once, or fail over between them
  without changing a line of it.
- **Verified by default.** Every inbound webhook is authenticated (by
  signature, token, or basic auth, depending on the provider), and anything it
  can't trust is rejected.
- **Delivery tracking out of the box.** Run the migrations and Postmaster
  records every send and keeps it current from the webhook stream. You get
  `delivered()`, `bounced()`, and `failed()` query scopes, a full per-message
  timeline, and an address suppression list that maintains itself.
- **Emails linked to your models.** Tie a send to an `Order` or a `User` and
  read its delivery state straight off the model.
- **A support dashboard.** A gated, cross-tenant UI for searching messages,
  watching events arrive live, and inspecting any stored email.
- **Sandbox delivery.** Intercept every outbound email in staging. It's
  recorded in your app's history but never actually sent.

## Requirements

- PHP 8.3+
- Laravel 12 or 13

## Installation

```bash
composer require stechstudio/laravel-postmaster
```

The webhook route registers itself, and there is nothing else to publish until
you opt into a feature that needs it. For everything that's left, run the
install wizard:

```bash
php artisan postmaster:install
```

It walks through the rest in one pass:

- detects which provider your `mail` config points at and gathers its webhook
  verification credential,
- optionally sets up suppression sync (collecting an API key for the providers
  that need one; see [Two-way sync with your provider](#two-way-sync-with-your-provider)),
- offers to enable persistence, content storage, or sandbox delivery,
- publishes and runs the package's migrations,
- runs `postmaster:verify` at the end to confirm the round trip works.

Everything goes into your `.env`, with the previous version backed up to
`.env.backup`. Re-running the wizard later is safe: it edits values in place
and offers to clean up entries left over from earlier choices, and it leaves
non-Postmaster lines alone. If you'd rather wire things up by hand,
[Securing webhooks](#securing-webhooks) below has each provider's credential.

## Getting started

The wizard handles everything inside your app. Two things still need your hand:

### 1. Point your provider at the webhook

Postmaster serves `POST /webhooks/postmaster/{provider}`. In your email
provider's dashboard, set the webhook URL to:

```
https://your-app.com/webhooks/postmaster/{provider}
```

…where `{provider}` is `sendgrid`, `postmark`, `mailgun`, `ses`, or `resend`.

### 2. Listen for the event

Every webhook becomes a normalized event you can listen for in a service
provider's `boot()`. The common path is a targeted event for what you
care about: `EmailBounced`, `EmailComplained`, `EmailDelivered`,
`EmailOpened`, `EmailClicked`, or `EmailDropped`.

```php
use Illuminate\Support\Facades\Event;
use STS\Postmaster\EmailBounced;

Event::listen(function (EmailBounced $event) {
    // The address bounced. Pause sends, flag the account, alert the team.
    logger()->warning("Email permanently failed for {$event->toAddress()}");
});
```

Each targeted event carries the same API (`$event->toAddress()`,
`$event->provider()`, `$event->bounceType()`, and so on). See
[The EmailEvent](#the-emailevent) for the full list.

For cross-cutting concerns (logging, audit feeds, anything that wants
every webhook regardless of status) listen on the umbrella `EmailEvent`,
fired alongside the targeted one:

```php
use STS\Postmaster\EmailEvent;

Event::listen(function (EmailEvent $event) {
    logger()->info("[{$event->provider()}] {$event->status()} for {$event->toAddress()}");
});
```

For anything beyond a few lines, use a dedicated listener class. Laravel
auto-discovers it, and it can implement `Illuminate\Contracts\Queue\ShouldQueue`
to process webhooks off the request cycle.

> **High-volume installs.** By default, parsing the webhook and dispatching
> the event(s) runs inline before the response returns to the provider.
> That's fine at low volume. Set `POSTMASTER_QUEUE_WEBHOOKS=true` to instead
> push a `ProcessWebhook` job onto the queue and respond `202 Accepted`
> immediately. Webhook signature verification stays inline either way.
> Optional `POSTMASTER_QUEUE_CONNECTION` / `POSTMASTER_QUEUE_NAME` isolate
> the job onto its own queue.

For the common "alert ops when a hard bounce lands" case the package ships
a drop-in notification:

```php
use Illuminate\Support\Facades\Notification;
use STS\Postmaster\EmailBounced;
use STS\Postmaster\Notifications\EmailDeliveryFailed;

Event::listen(function (EmailBounced $event) {
    if ($event->isPermanent()) {
        Notification::route('mail', config('ops.alerts_to'))
            ->notify(new EmailDeliveryFailed($event));
    }
});
```

It renders a short summary (address, status, bounce type, the provider's
reason). Subclass it to customise the body or to add `database`/`slack`
channels.

## Securing webhooks

Postmaster authenticates every inbound webhook and rejects anything it can't
trust. Each provider proves authenticity differently, so configure the one
credential yours needs. These are all `.env` values. Nothing to publish.

### SendGrid

Enable the Signed Event Webhook in SendGrid and copy the verification key:

```
POSTMASTER_SENDGRID_VERIFICATION_KEY=...
```

### Mailgun

```
POSTMASTER_MAILGUN_SIGNING_KEY=...   # falls back to MAILGUN_SECRET
```

### Amazon SES

SES delivers events through SNS. Subscribe an SNS topic to
`webhooks/postmaster/ses`. The package verifies the SNS message signature and
automatically completes the subscription-confirmation handshake. No secret to
configure.

### Resend

```
POSTMASTER_RESEND_SIGNING_SECRET=whsec_...
```

### Postmark

Postmark does not sign webhook payloads. Use HTTP basic auth (the default) or a
URL token:

```
POSTMASTER_AUTH_USERNAME=...
POSTMASTER_AUTH_PASSWORD=...
```

### Token or basic auth

Any provider can instead use a shared URL token or HTTP basic auth by setting
its `auth` to `token` or `basic`:

```
POSTMASTER_AUTH_TOKEN=mysecrettoken
# then append ?auth=mysecrettoken to the webhook URL
```

Each provider's verification method is its `auth` key in
`config/postmaster.php`: a built-in authorizer (`token`, `basic`,
`user-agent`) or a fully-qualified authorizer class. Providers default to
signature verification where the provider supports it.

## Verify your setup

The install wizard offers to run this at the end, but you can run it any time
to re-check a round trip after a config or provider change:

```bash
php artisan postmaster:verify
```

It detects your provider from the mail config, shows the exact webhook URL to
register, sends a real test email to an address you supply, then watches live
for the delivery webhook to come back. It reports each event the instant it
lands.

The live watch needs a cache store shared between your CLI and web processes
(`file`, `redis`, `database`, and so on). With the per-process `array` store
the command sends the test email and stops there.

## The EmailEvent

Every webhook becomes an `EmailEvent` with a normalized API. The methods are
the same whatever the provider:

```php
$event->provider();           // "SendGrid", "Postmark", "Mailgun", "SES", "Resend"
$event->status();             // one of the EmailEvent::STATUS_* constants
$event->toAddress();          // the recipient email address
$event->providerMessageId();  // the provider's message id
$event->occurredAt();         // when the event happened (DateTimeImmutable, UTC)
$event->bounceType();         // normalized bounce severity, or null
$event->isPermanent();        // true for a hard bounce or a block
$event->response();           // the provider's response/diagnostic detail
$event->reason();             // the provider's reason string
$event->code();               // the provider's status code
$event->clickedUrl();         // the URL clicked on a click event (else null)
$event->tags();               // Collection of tags/categories
$event->data();               // Collection of custom data
$event->payload();            // the raw provider payload
$event->toArray();            // everything above as an array
```

> **A note on provider casing.** Config keys are lowercase identifiers
> (`sendgrid`, `postmark`, `mailgun`, `ses`, `resend`). Stored and surfaced
> values are the canonical product name (`SendGrid`, `Postmark`, …). The
> `provider()` method, the `provider` column, and the dashboard all use the
> latter.

### Statuses

`status()` returns one of:

`EmailEvent::STATUS_ACCEPTED`, `STATUS_DEFERRED`, `STATUS_DELIVERED`,
`STATUS_BOUNCED`, `STATUS_DROPPED`, `STATUS_COMPLAINED`, `STATUS_OPENED`,
`STATUS_CLICKED`. There are five more for outbound records the package
writes itself: `STATUS_SENT`, `STATUS_SANDBOXED`, `STATUS_BLOCKED`,
`STATUS_LOGGED` (sends through Laravel's `log` driver), and `STATUS_CAPTURED`
(sends through the `array` driver). The last four are terminal; no webhook
will follow.

For comparing against a single value, every status has a matching `is*()`
predicate. They make a status check read clearly and they autocomplete:

```php
if ($event->isBounced())    { /* … */ }
if ($event->isDelivered())  { /* … */ }
if ($event->isFailed())     { /* bounced, dropped, or complained */ }
```

The same predicates are available on `EmailMessage` (where they answer
against the latest recorded status):

```php
if ($message->isFailed())   { /* the latest event was a failure */ }
```

### Targeted event classes

For the six lifecycle statuses worth dedicated listeners, a targeted event
class fires alongside the umbrella `EmailEvent` and lets you skip the
predicate:

| Targeted class | Fires for |
|---|---|
| `EmailDelivered` | `STATUS_DELIVERED` |
| `EmailBounced` | `STATUS_BOUNCED` |
| `EmailComplained` | `STATUS_COMPLAINED` |
| `EmailDropped` | `STATUS_DROPPED` |
| `EmailOpened` | `STATUS_OPENED` |
| `EmailClicked` | `STATUS_CLICKED` |

Every targeted class extends `EmailEvent`, so the API is the same. You get
all the accessors, predicates, and the correlated `emailMessage()` record
without needing to know which class fired:

```php
use STS\Postmaster\EmailBounced;

Event::listen(function (EmailBounced $event) {
    $event->toAddress();       // works
    $event->bounceType();      // works
    $event->emailMessage();    // same EmailMessage the umbrella listener saw
});
```

Statuses without a dedicated class (`STATUS_ACCEPTED`, `STATUS_DEFERRED`,
the outbound `STATUS_SENT`/`SANDBOXED`/`BLOCKED`/`LOGGED`/`CAPTURED`)
fire only the umbrella; listen on `EmailEvent` and use `is*()` if you
need them.

### Bounce classification

Beyond the action, bounces are normalized into a severity, so you can answer
"should I stop mailing this address?" without provider-specific knowledge:

- `EmailEvent::BOUNCE_HARD`: permanent, and safe to suppress.
- `EmailEvent::BOUNCE_SOFT`: transient, so retry later.
- `EmailEvent::BOUNCE_BLOCK`: blocked by reputation or policy.

`bounceType()` returns one of these (or `null` when the event is not a
bounce). `isPermanent()` is a shortcut for "hard or block".

## Invalid payloads

If a payload can't be turned into a valid event, the `on_invalid` config
setting decides what happens: `log` (default), `throw`, or `ignore`.

```
POSTMASTER_ON_INVALID=log
```

## Tracking delivery

Everything above is the core: a verified webhook endpoint and a normalized
event.

Postmaster also **records every outbound email** and keeps each record current
from the webhook stream, matching them up by provider message id, so you end
up with a queryable delivery history. Publish and run the migrations:

```bash
php artisan vendor:publish --tag=postmaster.migrations
php artisan migrate
```

That's it. Persistence is on. To run the package as a pure event dispatcher
with no database writes, set:

```
POSTMASTER_PERSISTENCE=false
```

This creates an `email_messages` table. Each row tracks a message's
`status`, `bounce_type`, `sent_at`, and `last_event_at`. The model
(`STS\Postmaster\Models\EmailMessage`) is swappable via the
`postmaster.persistence.message_model` config key.

It ships query scopes for the common lookups: `delivered()`, `bounced()`,
`complained()`, `opened()`, `clicked()`, `sent()`, `accepted()`, `deferred()`,
`dropped()`, the aggregate `failed()` (bounced, dropped, or complained), and
the generic `withStatus()`.

```php
use STS\Postmaster\Models\EmailMessage;

EmailMessage::bounced()->count();
EmailMessage::delivered()->where('sent_at', '>', now()->subDay())->get();
```

The package still dispatches `EmailEvent` in all modes. Persistence is just a
first-party listener layered on top.

With persistence on, each `EmailEvent` also carries the record it was
correlated to, so a listener can walk straight back to the originating message,
and through it to your own model:

```php
use Illuminate\Support\Facades\Event;
use STS\Postmaster\EmailEvent;

Event::listen(function (EmailEvent $event) {
    $order = $event->emailMessage?->related;   // the Order, User, ... it was sent for
});
```

`$event->emailMessage` is set by the package's own listener, which is
registered first, so it is populated for any listener of your own. It is null
when persistence is disabled or the webhook carries no message id to correlate
on.

### Recording the full timeline

The summary record above keeps only a message's *latest* status. That's enough
for "is this delivered?" but it can't represent a message that was opened three
times, and it overwrites the history as new events arrive.

With persistence on, the package also keeps every event as its own row in an
`email_activity` table (the initial send and each webhook alike), so a
message retains its complete delivery history. This is on by default; set
`POSTMASTER_RECORD_EVENTS=false` to keep only the summary record.

> **A note on naming.** "Event" is the live signal a webhook becomes (an
> `EmailEvent` value object you `Event::listen` for). "Activity" is the
> historical record we keep of those events in `email_activity`, plus
> address-level entries (manual suppress, unsuppress, sync add) that
> don't tie to a specific message. Same idea, two abstractions.

Each `EmailMessage` exposes its timeline, oldest first, via the `activity()`
relationship. `EmailAddress` exposes a symmetric `activity()` of every entry
that touched it (message lifecycle events sent to it, plus any address-level
entries):

```php
foreach ($message->activity as $entry) {
    // $entry->status:      sent, delivered, opened, bounced, ...
    // $entry->occurred_at: when it happened
    // $entry->bounce_type, $entry->response, $entry->reason, $entry->code
}

foreach ($address->activity as $entry) {
    // Every event that touched this address: message lifecycle events
    // from messages sent to it, plus suppressed/unsuppressed entries
    // for the address itself.
}
```

The summary record is still maintained alongside the timeline, and still
advances only on the newest event, so out-of-order webhooks can't make its
status regress. Query `EmailMessage` for current state, walk `events()` for
history.

Timeline rows accumulate one per event, so the package prunes them on a
schedule. There are two windows, because a six-month-old open is noise but
a six-month-old bounce is still evidence. Routine activity (sent, delivered,
opened, clicked, …) and failures (bounced, dropped, complained) are pruned
separately:

| Bucket | Default | `.env` |
|---|---|---|
| Routine | **90 days** | `POSTMASTER_PRUNE_ROUTINE_ACTIVITY_AFTER_DAYS` |
| Failures | **365 days** | `POSTMASTER_PRUNE_FAILED_ACTIVITY_AFTER_DAYS` |

Set either to `0` to disable that bucket. The pruner deletes whole rows;
summary records are left untouched.

### Tracking address suppression

The projections so far answer "what happened to this *message*?". Suppression
answers a different question: should I send to this *address* at all? The
message tables can't answer that cleanly, because a bad address poisons every
future send, not just the message that bounced.

With persistence on, the package keeps an `email_addresses` table: one row per
recipient with a current `status` of `active` or `suppressed`. This is on by
default; set `POSTMASTER_TRACK_ADDRESSES=false` to disable it.

An address is suppressed automatically on a hard bounce, a spam complaint, or a
drop. Soft bounces don't count, since they're transient.

Suppression is sticky against opens and clicks. A later delivery is the one
exception: if a `delivered` webhook arrives for an automatically-suppressed
address, the package flips it back to active. The reasoning matches what
`postmaster:sync` does for the provider side — a successful delivery is hard
proof the address works now, so the local row should reflect that. Manual
suppressions (operator-asserted via `Postmaster::suppress()`) are never auto-
cleared by any webhook; only `Postmaster::unsuppress()` lifts a manual one.

Check it before sending:

```php
use STS\Postmaster\Facades\Postmaster;

if (! Postmaster::isSuppressed($email)) {
    Mail::to($email)->send(new Invoice($order));
}
```

An address you've never sent to is treated as sendable. You can also manage
suppression yourself, for unsubscribes, abuse reports, anything:

```php
Postmaster::suppress($email);     // optional second arg: a reason string
Postmaster::unsuppress($email);
```

The `EmailAddress` model carries `active()` / `suppressed()` query scopes and
the `reason` / `suppressed_at` columns for the rest.

**Suppression is global, never per tenant.** A provider suppresses a
hard-bouncing address across your whole account regardless of which tenant sent
the mail, so a per-tenant view would just disagree with reality.

#### Block suppressed sends automatically

The check above is opt-in per send. To make every outbound to a suppressed
address fail safely at the source, set:

```
POSTMASTER_BLOCK_SUPPRESSED=true
```

Anything addressed to a suppressed recipient is intercepted before it reaches
the mail transport, recorded with status `blocked` (so the attempt is visible
in the dashboard), and dropped. Bypass it per send by lifting the suppression
or by skipping the check yourself. There's no per-message bypass flag.

#### Two-way sync with your provider

The webhook stream is one feed into the suppression table. Every time a
provider tells us about a bounce or complaint, we record it. But it's not
the only source of truth. The provider has its own authoritative list, and
admins can clear suppressions in the provider's dashboard or via their API.
If you only listen to webhooks you can't see those clearances and your
local table drifts out of date.

`postmaster:sync` pulls each configured provider's current suppression list
and reconciles it with our local table. New provider suppressions land
here, and addresses the provider no longer holds are cleared locally
(unless they're **manual** suppressions, which are operator decisions and
never auto-cleared). It runs daily at 04:00 once persistence is on, and
can be invoked by hand:

```bash
php artisan postmaster:sync                    # all configured providers
php artisan postmaster:sync --provider=sendgrid
php artisan postmaster:sync --dry-run          # report without writing
```

Each provider needs two things: its official SDK installed (suggested in
`composer.json`, not required) and an API key configured. With either
missing, that provider is skipped with an informative line:

| Provider | SDK | Config key |
|---|---|---|
| SendGrid | `composer require sendgrid/sendgrid` | `POSTMASTER_SENDGRID_API_KEY` (or `SENDGRID_API_KEY`) |
| Postmark | `composer require wildbit/postmark-php` | `POSTMASTER_POSTMARK_SERVER_TOKEN` (or `POSTMARK_TOKEN`) |
| Mailgun  | `composer require mailgun/mailgun-php` | `POSTMASTER_MAILGUN_API_KEY` (or `MAILGUN_SECRET`) + `POSTMASTER_MAILGUN_DOMAIN` |
| Amazon SES | `composer require aws/aws-sdk-php` | Uses the standard AWS credential chain |
| Resend | — | Resend has a full API but no suppression-list resource (suppressions are dashboard-only); sync is a no-op for Resend, and the local table is fed entirely by the webhook stream |

**Unsuppress is two-way too.** Each suppression row records which
provider(s) put it on the list (via webhook events or sync). When you
call `Postmaster::unsuppress($address)`, or click Unsuppress in the
dashboard, the local row is lifted and every recorded provider with
API support is asked to clear theirs. The method returns an array
with `cleared` (providers we successfully called) and `manual`
(providers without API support, where the suppression has to be
cleared in the provider's own dashboard).

The dashboard reflects this: the Unsuppress button appears only when
at least one of an address's recorded providers has a usable API. For
rows whose only source is a provider without one (Resend today), the
button is replaced with a "Manage in {Provider}" hint instead of
implying an action that can't actually do what it suggests.

### Storing message content

By default a record holds only delivery metadata. Enable content storage and
each record also keeps a full representation of the email: sender, recipients
(to/cc/bcc), subject, HTML and text bodies, and attachment filenames. This is
captured from the message itself at send time, so it works the same for every
provider.

```
POSTMASTER_STORE_CONTENT=true
```

> Message bodies are large and routinely contain personal data or secrets
> (password-reset links, magic-login tokens). This is why it's off by default.
> Attachment **contents** are never stored, only their filenames. And because
> content is captured before sending, it won't reflect the click-tracking link
> rewriting some providers apply afterward.

Because of the size and sensitivity, content carries a short retention window
by default (30 days), after which the daily prune clears the content columns
and leaves the record itself in place. Adjust or disable from `.env`:

```
POSTMASTER_PRUNE_CONTENT_AFTER_DAYS=14   # tighter
POSTMASTER_PRUNE_CONTENT_AFTER_DAYS=0    # disable pruning entirely (not advised for content)
```

Stored content and timeline events share one daily prune command. Run it by
hand any time:

```bash
php artisan postmaster:prune              # both content and events
php artisan postmaster:prune --content    # only stored content
php artisan postmaster:prune --activity   # only timeline activity
```

A single email can override the global setting. A Mailable's `Tracking` carries
a `storeContent` field, and the notification `MailMessage` has fluent
`storeContent()` / `dontStoreContent()` methods. So a password-reset or MFA
email can keep its body out of the database even when storage is on, and a
specific email can be captured even when it's off:

```php
// in a Mailable's postmaster() method
return new Tracking(related: $this->user, storeContent: false);

// on a notification MailMessage
return (new MailMessage)->subject('Your login code')->dontStoreContent();
```

### Resending a recorded email

Any recorded `EmailMessage` with stored content can be replayed through the
configured mailer:

```php
$message->resend();

// or equivalently
Postmaster::resend($message);
Postmaster::resend($messageId);
```

The new send carries over everything we can reconstruct from the recorded
row — sender, To/Cc/Bcc envelope, subject, html and text bodies, related /
recipient / tenant context, tags — and gets a `resent` tag of its own. The
new row's `resent_from_id` points back to the original so the chain is
queryable:

```php
$message->resentFrom;                  // BelongsTo — the original, or null
$message->resends;                     // HasMany — direct resends of this row
$message->resendChain();               // the whole lineage, ordered by sent_at

// Did any retry of this bounced message eventually deliver?
$message->resends()->delivered()->exists();
```

Attachments are not restored — the package only persists their filenames,
never their bytes. Resend throws `RuntimeException` when there's no stored
content to replay; enable `POSTMASTER_STORE_CONTENT` ahead of the original
send.

App code that builds its own resend outside `Postmaster::resend()` (e.g. a
custom Mailable for a specific retry workflow) can declare the link via
`Tracking`:

```php
return new Tracking(
    related:     $this->order,
    recipient:   $this->customer,
    resent_from: $originalMessage,
);
```

That populates the FK on the new row so the dashboard's chain card and the
relationship helpers see the resend the same way they see one from
`Postmaster::resend()`.

### Relating emails to your models

Recorded emails can be linked back to two of your models: the one the email
is **about** (an `Order`, an `Invoice`) and the one the email is **for** (the
`User` it was sent to). Keeping these distinct means a user can list every
email they've ever received without having to traverse every business record
they touch.

Add the `TracksMailable` trait to a Mailable and declare both with a
`postmaster()` method that returns a `Tracking` object. It works the same way
as Laravel's own `envelope()` and `content()`:

```php
use Illuminate\Mail\Mailable;
use STS\Postmaster\Concerns\TracksMailable;
use STS\Postmaster\Tracking;

class OrderConfirmation extends Mailable
{
    use TracksMailable;

    public function __construct(public Order $order) {}

    public function postmaster(): Tracking
    {
        return new Tracking(
            related: $this->order,              // what the email is about
            recipient: $this->order->customer,  // who the email is for
            tenant: $this->order->account_id,   // optional; see Multitenancy below
            tags: ['billing'],                  // optional; see below
        );
    }

    public function envelope(): Envelope { /* ... */ }
    public function content(): Content { /* ... */ }
}
```

Postmaster reads `postmaster()` when the mailable is sent, after a queued job
is dequeued (so it's queue-safe), and records what the `Tracking` declares.
Every field is optional, so declare only the ones that apply.

> Need to set something dynamically instead? `TracksMailable` also exposes
> `relatedTo($model)`, `forRecipient($model)`, `forTenant($tenant)`,
> `storeContent()` and `dontStoreContent()`. Call them anywhere before the
> mailable is sent.

For apps where every email is to a known `User`, the recipient can be
resolved from the to-address automatically. Declare a resolver once in a
service provider and skip `recipient:` on every Mailable:

```php
use STS\Postmaster\Facades\Postmaster;

Postmaster::resolveRecipientByEmail(User::class);
```

That's the one-liner for the "look up the model by its `email` column"
case. Pass `column: 'whatever'` to match against a different column. The
address is normalized (lower-cased, trimmed) before the lookup, so a
mixed-case webhook still finds the row.

For anything else (a derived column, a join, a custom query), drop down to
the lower-level form:

```php
Postmaster::resolveRecipientUsing(
    fn ($address) => Contact::query()
        ->where('primary_email', $address)
        ->orWhere('billing_email', $address)
        ->first()
);
```

An explicit `Tracking(recipient: …)` declaration always wins over either
form of the resolver, which is useful when an email about User A is sent
to User B.

#### Multi-recipient sends

Each envelope recipient (To, Cc, Bcc) gets its own `email_messages` row,
all sharing the provider message id and the related/tenant/tags. That's
because providers fire delivery and bounce webhooks **per recipient**, and
one row per address keeps each delivery state accurate. A bounce for
`bob@x` lands on bob's row; alice's stays untouched.

For sends where each recipient maps to a different user, declare the map
inline with `Tracking(recipients: [...])`:

```php
return new Tracking(
    related: $this->order,
    recipients: [
        'alice@example.com' => $alice,
        'bob@example.com'   => $bob,
    ],
);
```

Lookup is case-insensitive. Addresses not in the map fall through to
`Postmaster::resolveRecipientUsing()`, so you only need to declare the
ones the resolver wouldn't find.

The dashboard's message list shows a small `cc` / `bcc` tag next to the
address for non-To rows. The message detail page lists the other rows
of the same outbound submission under an "Also sent to" block, each
linking to its own detail page.

### Tagging

`Tracking`'s `tags` are Laravel's own mailable tags. Postmaster records them on
the message so you can categorise and query your recorded mail:

```php
EmailMessage::taggedWith('billing')->bounced()->get();
```

Because they're Laravel's tags, a notification's `MailMessage` sets them with
its native `tag()` method, and Symfony forwards them to providers whose
transport supports tags. Postmaster reads and records whatever is there, so a
plain Mailable calling `tag()` directly is recorded just the same.

Add `HasEmailMessages` to the business-record model and `IsEmailRecipient` to
the User-side model:

```php
use STS\Postmaster\Concerns\HasEmailMessages;
use STS\Postmaster\Concerns\IsEmailRecipient;

class Order extends Model
{
    use HasEmailMessages;   // emails this order is *about*
}

class User extends Model
{
    use IsEmailRecipient;   // emails this user has *received*
}
```

Both traits expose the same shape (`emailMessages()`, `latestEmailMessage()`,
`emailDeliveryFailed()`) but key off different polymorphic links, so each
model only sees the emails it owns:

```php
$order->emailMessages;                        // every email about this order
$order->emailMessages()->failed()->exists();  // did any of them fail?

$user->emailMessages;                         // every email this user received
$user->latestEmailMessage();                  // the most recent one, or null
```

Both associations are carried on the message in-process only, written as
headers and read and stripped *before* the email is transmitted, so nothing
about the related or recipient model is ever exposed in the outbound email.

> Both use polymorphic relationships. If your models use UUID/ULID primary
> keys, change `nullableMorphs('related')` and `nullableMorphs('recipient')`
> to the matching variants in the published migration.

### From a notification

Notifications send through the same mailer, so recording, content capture, and
status correlation all work for notification emails with no extra setup. A
notification's `toMail()` returns a `MailMessage` rather than a Mailable, so to
*associate* one, swap Laravel's `MailMessage` for Postmaster's. It's a drop-in
subclass with the same fluent `relatedTo()` and `forTenant()` methods:

```php
use STS\Postmaster\Notifications\TrackedMailMessage;

public function toMail($notifiable)
{
    return (new MailMessage)
        ->subject('Your order shipped')
        ->line('Your order is on its way.')
        ->relatedTo($this->order)
        ->forTenant($this->order->tenant);
}
```

Only the import changes. Postmaster's `MailMessage` is Laravel's with the
`WithTracking` trait applied, so every notification builder method
(`line()`, `action()`, and so on) works unchanged.

Already maintain your own `MailMessage` subclass? Add the `WithTracking`
trait to it directly. It works on anything exposing `withSymfonyMessage()`.

Or, to skip subclassing entirely, pass the `Postmaster` builders straight to
`withSymfonyMessage()` on a plain `MailMessage`:

```php
use STS\Postmaster\Facades\Postmaster;

return (new MailMessage)
    ->subject('Your order shipped')
    ->line('Your order is on its way.')
    ->withSymfonyMessage(Postmaster::relatedTo($this->order))
    ->withSymfonyMessage(Postmaster::forTenant($this->order->tenant));
```

### Multitenancy

In a multitenant app you'll often want every recorded email tagged with its
owning tenant, including emails that aren't tied to any `related` model, so a
tenant can see all of its delivery activity at once.

Register a tenant resolver, typically in a service provider:

```php
use STS\Postmaster\Facades\Postmaster;

Postmaster::resolveTenantUsing(fn () => tenant());
```

The resolver may return a tenant model or its key, and is called lazily when
each email is recorded, so it resolves correctly per request or queued job.

If tenant context isn't available globally (e.g. inside a queued job that
doesn't bootstrap tenancy), a Mailable can declare its tenant explicitly in its
`Tracking`. That always takes precedence over the resolver:

```php
class OrderConfirmation extends Mailable
{
    use TracksMailable;

    public function postmaster(): Tracking
    {
        return new Tracking(
            related: $this->order,
            tenant: $this->order->tenant,
        );
    }
}
```

Query a tenant's activity:

```php
EmailMessage::forTenant($tenant)->bounced()->get();
```

To get a `tenant()` relationship on `EmailMessage` (and tenant labels in the
dashboard), tell Postmaster your tenant model. Register it in a service
provider, with no need to publish the config file:

```php
use STS\Postmaster\Facades\Postmaster;

Postmaster::useTenantModel(App\Models\Tenant::class);
```

Or, if you publish the config, set `persistence.tenant_model` there instead.

A few notes for multitenant setups:

- **Inbound webhooks have no tenant context.** Providers POST to one global
  URL. Correlation runs by provider message id and deliberately ignores global
  scopes, so a tenant-scoped model is still updated correctly.
- **Database-per-tenant:** point `persistence.connection` at a shared
  connection. The webhook handler can't know which tenant database to write to,
  so the table must live somewhere globally reachable.
- The tenant column defaults to `tenant_id` (configurable via
  `persistence.tenant_column`) and is an `unsignedBigInteger`. Apps with
  UUID/ULID tenant keys should change its type in the published migration.

## Dashboard

A gated, cross-tenant superadmin view of all recorded email activity. Browse
and search messages, watch events stream in live, manage suppression. It's
built for support, and every screen is a linkable URL.

It's off by default. Enable it, and it mounts at `/postmaster`:

```
POSTMASTER_DASHBOARD=true
```

The dashboard reads the persistence tables, so it requires the
[persistence layer](#tracking-delivery) to be enabled.

### Authorization

The dashboard deliberately shows email across *every* tenant. It's the one
place tenant isolation is bypassed by design, so access must be gated. Register
an authorization callback, Telescope-style, in a service provider:

```php
use STS\Postmaster\Facades\Postmaster;

Postmaster::auth(fn ($request) => $request->user()?->isSuperAdmin());
```

With no callback registered, access is allowed **only in the `local`
environment**, so the dashboard is never unguarded in production by accident.

### Screens

- **Overview.** Headline counts and an activity chart over a selectable
  timeframe, plus recent-messages and live recent-activity cards.
- **Messages.** A filterable inbox (status, provider, tag, tenant, recipient,
  subject, date range). Each message opens to its delivery timeline and stored
  content, rendered in a sandboxed, CSP-restricted frame. Click events show
  the URL the recipient clicked, inline on the timeline.
- **Person view.** Click the Recipient row on a message detail page to land
  on a page listing every email recorded against that recipient model. The
  "all the email a user has received" view.
- **Resend.** A button on the message detail page replays the stored email
  through the configured mailer, keeping the original's related model,
  recipient, tenant, and tags, plus a `resent` tag of its own. The new row
  links back to the original via `resent_from_id`, and the message detail
  shows a **Resend chain** sidebar card walking the lineage so a support
  reviewer can see at a glance whether a retry-after-unsuppress ever
  delivered. Requires stored content; attachments are not restored.

  The button is hidden when the recipient is currently suppressed — clear
  the suppression first (the Addresses screen has the unsuppress action).
  Rapid duplicate clicks are throttled per-message
  (`POSTMASTER_DASHBOARD_RESEND_THROTTLE_SECONDS=60` by default).
- **Activity.** A filterable, paginated stream of every recorded event, drawn
  from the timeline (on by default with persistence).
- **Addresses.** The suppression list.

Every datetime is stored UTC and displayed in the viewer's browser timezone
by default. A small clock toggle in the header swaps between that and UTC;
the choice is per-browser (localStorage). The chart's daily buckets stay
UTC-anchored either way.

There are no assets to publish and no CDN. The dashboard serves its own
stylesheet and its one client-side dependency (Alpine) straight from the
package. The path and middleware are configurable under the `dashboard` config
key.

## Sandbox delivery

In a staging environment you often want emails to *appear* in your app, so you
can see what was sent, to whom, and with what content, without anything
actually landing in a real inbox. Sandbox delivery does exactly that:

```dotenv
POSTMASTER_DELIVERY=sandbox
```

With this set, every outbound email is intercepted before it reaches the mail
transport and **never sent**. With persistence enabled it is still recorded,
with a `sandbox` status, so it shows up in your app's email history exactly
like a real send, including its related model, tenant, and (if content storage
is on) its rendered body.

```php
EmailMessage::sandbox()->get();   // everything intercepted in sandbox mode
```

A sandboxed message is **terminal**: it never reached a provider, so no
delivery/open/bounce webhooks will ever follow. Render the `sandboxed` status
distinctly in your UI rather than as a pending send.

> Sandbox is provider-agnostic. It works the same no matter which provider you
> send through. It needs persistence on to record anything (the default).
> Without persistence, mail is still suppressed but nothing is stored, at
> which point Laravel's `log` mailer is the simpler tool.

Because sandbox silently drops *all* mail, enabling it in `production` is almost
never intended. Postmaster logs a warning at boot if it sees that, and
`postmaster:verify` reports it rather than attempting a round-trip check.

The `POSTMASTER_DELIVERY` setting is an enum, and `normal` is the default. A
`redirect` mode, which would send every email to a single catch-all address, is
reserved for a future release.

## Configuration

The defaults work out of the box. To change the webhook path, adjust
per-provider settings, or tweak persistence, publish the config file:

```bash
php artisan vendor:publish --tag=postmaster.config
```

The webhook route is registered for you. To register it yourself instead, say
on a custom domain or prefix or with your own middleware, set
`POSTMASTER_REGISTER_ROUTE=false` and call `Postmaster::routes()` from your own
route file.

## Custom providers

Register your own provider at runtime with a resolver closure:

```php
use STS\Postmaster\Facades\Postmaster;
use STS\Postmaster\Provider;

Postmaster::extend('myprovider', function (array $config) {
    return new Provider('myprovider', MyAdapter::class, fn ($request) => true);
});
```

An adapter implements `STS\Postmaster\Contracts\Adapter` (extending
`STS\Postmaster\Providers\AbstractAdapter` covers most of it).

## License

MIT. See [LICENSE.md](LICENSE.md).

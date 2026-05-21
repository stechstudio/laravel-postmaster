# Postmaster

[![Latest Version on Packagist](https://img.shields.io/packagist/v/stechstudio/laravel-postmaster.svg?style=flat-square)](https://packagist.org/packages/stechstudio/laravel-postmaster)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE.md)

Your Laravel app sends mail through SendGrid, Postmark, Mailgun, Amazon SES, or
Resend. Each of those providers can POST a webhook back to you when something
happens to a message — a delivery, an open, a bounce, a complaint — but every
provider authenticates, shapes, and names those events differently.

Postmaster accepts webhooks from any supported provider and normalizes them
into a single Laravel `EmailEvent`. You listen for one event and react — with
no provider-specific code in your app. Switch on the optional persistence layer
and it also records every outbound message, keeping a queryable delivery
history that stays current as events arrive.

## Supported providers

SendGrid, Postmark, Mailgun, Amazon SES, and Resend.

## Requirements

- PHP 8.2+
- Laravel 12 or 13

## Installation

```bash
composer require stechstudio/laravel-postmaster
```

That's all the setup there is — the webhook route is registered automatically.

## Quick start

### 1. Point your provider at the webhook

The package serves `POST .hooks/postmaster/{provider}`. In your email
provider's dashboard, set the webhook URL to:

```
https://your-app.com/.hooks/postmaster/{provider}
```

…where `{provider}` is `sendgrid`, `postmark`, `mailgun`, `ses`, or `resend`.

### 2. Listen for the event

In a service provider's `boot()` method:

```php
use Illuminate\Support\Facades\Event;
use STS\Postmaster\EmailEvent;

Event::listen(function (EmailEvent $event) {
    if ($event->isPermanent()) {
        // A hard bounce or a block — safe to suppress this address.
        MySuppressionList::add($event->getRecipient());
    }
});
```

That's the whole integration. Every webhook — a delivery, open, bounce, or
complaint, from any provider — arrives as one normalized `EmailEvent`.

For anything substantial, use a dedicated listener class instead — Laravel
auto-discovers it, and it can implement `Illuminate\Contracts\Queue\ShouldQueue`
to process webhooks off the request cycle.

> **Before going live**, set up [webhook verification](#verifying-webhooks) so
> the package can trust inbound requests. Unverified webhooks are rejected by
> default — it's one credential per provider.

## Checking your setup

To confirm the whole round trip, run:

```bash
php artisan postmaster:verify
```

It detects your provider from the mail config, shows the exact webhook URL to
register, sends a real test email to an address you supply, then watches live
for the delivery webhook to come back — reporting each event the instant it
lands.

The live watch needs a cache store shared between your CLI and web processes
(`file`, `redis`, `database`, …). With the per-process `array` store the
command sends the test email and stops there.

## The EmailEvent

Every webhook becomes an `EmailEvent` with a normalized API:

```php
$event->getProvider();    // "SendGrid", "Postmark", "Mailgun", "SES", "Resend"
$event->getAction();      // one of the EmailEvent::EVENT_* constants
$event->getRecipient();   // the recipient email address
$event->getMessageId();   // the provider's message id
$event->getTimestamp();   // unix timestamp (int)
$event->getDate();        // the timestamp as a DateTimeImmutable (UTC)
$event->getBounceType();  // normalized bounce severity, or null
$event->isPermanent();    // true for a hard bounce or a block
$event->getResponse();    // the provider's response/diagnostic detail
$event->getReason();      // the provider's reason string
$event->getCode();        // the provider's status code
$event->getTags();        // Collection of tags/categories
$event->getData();        // Collection of custom data
$event->getPayload();     // the raw provider payload
$event->toArray();        // everything above as an array
```

### Actions

`getAction()` returns one of:

`EmailEvent::EMAIL_ACCEPTED`, `EVENT_DEFERRED`, `EVENT_DELIVERED`,
`EVENT_BOUNCED`, `EVENT_DROPPED`, `EVENT_COMPLAINED`, `EVENT_OPENED`,
`EVENT_CLICKED`.

### Bounce classification

Beyond the action, bounces are normalized into a severity so you can answer
"should I stop mailing this address?" without provider-specific knowledge:

- `EmailEvent::BOUNCE_HARD` — permanent; safe to suppress.
- `EmailEvent::BOUNCE_SOFT` — transient; retry later.
- `EmailEvent::BOUNCE_BLOCK` — blocked by reputation/policy.

`getBounceType()` returns one of these (or `null` when the event is not a
bounce). `isPermanent()` is a shortcut for "hard or block".

## Verifying webhooks

The package verifies every inbound webhook and rejects anything it can't
trust. Each provider authenticates its webhooks differently — configure the
one credential your provider needs. These are `.env` values; no config file
needs to be published.

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
`.hooks/postmaster/ses`; the package verifies the SNS message signature and
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
`config/postmaster.php` — a built-in authorizer (`token`, `basic`,
`user-agent`) or a fully-qualified authorizer class. Providers default to
signature verification where the provider supports it.

## Invalid payloads

If a payload can't be turned into a valid event, the `on_invalid` config
setting decides what happens: `log` (default), `throw`, or `ignore`.

```
POSTMASTER_ON_INVALID=log
```

## Optional persistence

So far the package is a pure event dispatcher. Enable persistence and it will
also **record every outbound email** and keep that record up to date as
webhook events arrive — correlated by provider message id — giving you a
queryable delivery history.

```
POSTMASTER_PERSISTENCE=true
```

Publish and run the migration:

```bash
php artisan vendor:publish --tag=postmaster.migrations
php artisan migrate
```

This creates an `email_messages` table. Each row tracks a message's
`status`, `bounce_type`, `sent_at`, and `last_event_at`. The model
(`STS\Postmaster\Models\EmailMessage`) is swappable via the
`postmaster.persistence.model` config key.

It ships query scopes for the common lookups — `delivered()`, `bounced()`,
`complained()`, `opened()`, `clicked()`, `sent()`, `accepted()`, `deferred()`,
`dropped()`, and the generic `withStatus()`:

```php
use STS\Postmaster\Models\EmailMessage;

EmailMessage::bounced()->count();
EmailMessage::delivered()->where('sent_at', '>', now()->subDay())->get();
```

The package still dispatches `EmailEvent` in all modes — persistence is just a
first-party listener layered on top.

### Recording the full timeline

The summary record above keeps only a message's *latest* status. That's enough
for "is this delivered?" but it can't represent a message that was opened three
times, and it overwrites the history as new events arrive.

Turn on timeline recording and the package also keeps every event — the
initial send and each webhook — as its own row, so a message retains its
complete delivery history:

```
POSTMASTER_RECORD_EVENTS=true
```

Each `EmailMessage` then exposes its timeline, oldest first, via the `events()`
relationship — ideal for an activity feed:

```php
foreach ($message->events as $event) {
    // $event->status      — sent, delivered, opened, bounced, ...
    // $event->occurred_at  — when it happened
    // $event->bounce_type, $event->response, $event->reason, $event->code
}
```

The summary record is still maintained alongside the timeline (and still
advances only on the newest event, so out-of-order webhooks can't make its
status regress) — query `EmailMessage` for current state, walk `events()` for
history.

Timeline rows accumulate one per event, so pair them with a retention window.
Set the number of days to keep events and the package schedules a daily prune
automatically — whole rows are deleted, the summary records untouched:

```
POSTMASTER_PRUNE_EVENTS_AFTER_DAYS=90
```

You can also run it on demand:

```bash
php artisan postmaster:prune-events
```

### Tracking address suppression

The projections so far answer "what happened to this *message*?". Suppression
answers a different question — "should I send to this *address* at all?" — one
the message tables can't answer cleanly, because a bad address poisons every
future send, not just the message that bounced.

Turn it on and the package keeps an `email_addresses` table: one row per
recipient with a current `status` of `active` or `suppressed`.

```
POSTMASTER_TRACK_ADDRESSES=true
```

An address is suppressed automatically on a hard bounce, a spam complaint, or a
drop — soft bounces don't count, they're transient. Suppression is sticky: a
later delivery or open never revives an address, only an explicit unsuppress
does.

Check it before sending:

```php
use STS\Postmaster\Facades\Postmaster;

if (! Postmaster::isSuppressed($email)) {
    Mail::to($email)->send(new Invoice($order));
}
```

An address you've never sent to is treated as sendable. You can also manage
suppression yourself — for unsubscribes, abuse reports, anything:

```php
Postmaster::suppress($email);     // optional second arg: a reason string
Postmaster::unsuppress($email);
```

The `EmailAddress` model carries `active()` / `suppressed()` query scopes and
the `reason` / `suppressed_at` columns for the rest.

**Suppression is global, never per tenant.** A provider suppresses a
hard-bouncing address across your whole account regardless of which tenant sent
the mail — a per-tenant view would simply disagree with reality.

> This table is built from the webhooks you receive, so it reflects
> suppressions caused by mail sent through this package. Pulling a provider's
> full suppression list, or clearing suppressions back on the provider's side,
> would need each provider's API and isn't part of this layer.

### Storing message content

By default a record holds only delivery metadata. Enable content storage and
each record also keeps a full representation of the email — sender,
recipients (to/cc/bcc), subject, HTML and text bodies, and attachment
filenames. This is captured from the message itself at send time, so it works
the same for every provider.

```
POSTMASTER_STORE_CONTENT=true
```

> Message bodies are large and routinely contain personal data or secrets
> (password-reset links, magic-login tokens). This is why it's off by default.
> Attachment **contents** are never stored — only their filenames. And because
> content is captured before sending, it won't reflect the click-tracking link
> rewriting some providers apply afterward.

Because of the size and sensitivity, pair it with a retention window. Set the
number of days to keep content and the package schedules a daily prune
automatically — the record is kept, only the content columns are cleared:

```
POSTMASTER_PRUNE_CONTENT_AFTER_DAYS=30
```

You can also run it on demand:

```bash
php artisan postmaster:prune-content
```

### Relating emails to your models

Recorded emails can be linked back to one of your own models — an `Order`, a
`User`, anything — so that model can list its own delivery history (great for
an admin activity feed that highlights bounces and complaints).

Add the `TracksEmailEvents` trait to a Mailable and call `relatedTo()`:

```php
use Illuminate\Mail\Mailable;
use STS\Postmaster\Concerns\TracksEmailEvents;

class OrderConfirmation extends Mailable
{
    use TracksEmailEvents;

    public function __construct(public Order $order) {}

    public function build()
    {
        return $this->relatedTo($this->order)
            ->subject('Your order is confirmed')
            ->view('emails.order-confirmation');
    }
}
```

Add the `HasEmailMessages` trait to the related model:

```php
use STS\Postmaster\Concerns\HasEmailMessages;

class Order extends Model
{
    use HasEmailMessages;
}
```

Now every email's lifecycle is queryable from the model:

```php
$order->emailMessages;                  // every email sent for this order
$order->emailMessages()->bounced()->exists();
```

The association is carried on the message in-process only — written as a
header, then read and stripped *before* the email is transmitted — so nothing
about the related model is ever exposed in the outbound email.

> Uses a polymorphic relationship. If your models use UUID/ULID primary keys,
> change `nullableMorphs('related')` to the matching variant in the published
> migration.

### From a notification

Notifications send through the same mailer, so recording, content capture, and
status correlation all work for notification emails with no extra setup. A
notification's `toMail()` returns a `MailMessage` rather than a Mailable, so to
*associate* one, swap Laravel's `MailMessage` for Postmaster's — a drop-in
subclass with the same fluent `relatedTo()` / `forTenant()` methods:

```php
use STS\Postmaster\Notifications\MailMessage;

public function toMail($notifiable)
{
    return (new MailMessage)
        ->subject('Your order shipped')
        ->line('Your order is on its way.')
        ->relatedTo($this->order)
        ->forTenant($this->order->tenant);
}
```

Only the import changes — Postmaster's `MailMessage` is Laravel's with the
`TracksEmailEvents` trait applied, so every notification builder method
(`line()`, `action()`, …) works unchanged.

Already maintain your own `MailMessage` subclass? Add the `TracksEmailEvents`
trait to it directly — the trait works on anything exposing
`withSymfonyMessage()`.

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
owning tenant — including emails that aren't tied to any `related` model — so a
tenant can see all of its delivery activity at once.

Register a tenant resolver, typically in a service provider:

```php
use STS\Postmaster\Facades\Postmaster;

Postmaster::resolveTenantUsing(fn () => tenant());
```

The resolver may return a tenant model or its key, and is called lazily when
each email is recorded — so it resolves correctly per request or queued job.

If tenant context isn't available globally (e.g. inside a queued job that
doesn't bootstrap tenancy), a Mailable can declare its tenant explicitly with
`forTenant()`. This always takes precedence over the resolver:

```php
class OrderConfirmation extends Mailable
{
    use TracksEmailEvents;

    public function build()
    {
        return $this->relatedTo($this->order)
            ->forTenant($this->order->tenant)
            ->subject('Your order is confirmed');
    }
}
```

Query a tenant's activity:

```php
EmailMessage::forTenant($tenant)->bounced()->get();
```

To get a `tenant()` relationship on `EmailMessage`, point config at your tenant
model:

```php
'persistence' => [
    'tenant_model' => App\Models\Tenant::class,
],
```

A few notes for multitenant setups:

- **Inbound webhooks have no tenant context** — providers POST to one global
  URL. Correlation runs by provider message id and deliberately ignores global
  scopes, so a tenant-scoped model is still updated correctly.
- **Database-per-tenant:** point `persistence.connection` at a shared
  connection. The webhook handler can't know which tenant database to write to,
  so the table must live somewhere globally reachable.
- The tenant column defaults to `tenant_id` (configurable via
  `persistence.tenant_column`) and is an `unsignedBigInteger` — apps with
  UUID/ULID tenant keys should change its type in the published migration.

## Sandbox delivery

In a staging environment you often want emails to *appear* in your app — so you
can see what was sent, to whom, with what content — without anything actually
landing in a real inbox. Sandbox delivery does exactly that:

```dotenv
POSTMASTER_DELIVERY=sandbox
```

With this set, every outbound email is intercepted before it reaches the mail
transport and **never sent**. With persistence enabled it is still recorded —
with a `sandbox` status — so it shows up in your app's email history exactly
like a real send, including its related model, tenant, and (if content storage
is on) its rendered body.

```php
EmailMessage::sandbox()->get();   // everything intercepted in sandbox mode
```

A sandboxed message is **terminal**: it never reached a provider, so no
delivery/open/bounce webhooks will ever follow. Render the `sandbox` status
distinctly in your UI rather than as a pending send.

> Sandbox is provider-agnostic — it works the same whether you send through
> SES, Mailgun, Postmark, SendGrid, or Resend. It needs persistence
> (`POSTMASTER_PERSISTENCE=true`) to record anything; without it, mail is still
> suppressed but nothing is stored — at which point Laravel's `log` mailer is
> the simpler tool.

Because sandbox silently drops *all* mail, enabling it in `production` is almost
never intended — Postmaster logs a warning at boot if it sees that, and
`postmaster:verify` reports it rather than attempting a round-trip check.

The `POSTMASTER_DELIVERY` setting is an enum (`normal` is the default); a
`redirect` mode — send every email to a single catch-all address — is reserved
for a future release.

## Configuration

The defaults work out of the box. To customize them — change the webhook path,
adjust per-provider settings, tweak persistence — publish the config file:

```bash
php artisan vendor:publish --tag=postmaster.config
```

The webhook route is registered for you. To register it yourself instead — a
custom domain, prefix, or middleware — set `POSTMASTER_REGISTER_ROUTE=false`
and call `Postmaster::routes()` from your own route file.

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

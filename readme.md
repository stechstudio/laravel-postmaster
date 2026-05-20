# Laravel Email Events

[![Latest Version on Packagist](https://img.shields.io/packagist/v/stechstudio/laravel-email-events.svg?style=flat-square)](https://packagist.org/packages/stechstudio/laravel-email-events)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE.md)

Your Laravel app sends mail through SendGrid, Postmark, Mailgun, Amazon SES, or
Resend. Each of those providers can POST a webhook back to you when something
happens to a message — a delivery, an open, a bounce, a complaint — but every
provider authenticates, shapes, and names those events differently.

This package accepts webhooks from any supported provider and normalizes them
into a single Laravel `EmailEvent`. You listen for one event and react — with
no provider-specific code in your app.

## Supported providers

SendGrid, Postmark, Mailgun, Amazon SES, and Resend.

## Requirements

- PHP 8.2+
- Laravel 12 or 13

## Installation

```bash
composer require stechstudio/laravel-email-events
```

That's all the setup there is — the webhook route is registered automatically.

## Quick start

### 1. Point your provider at the webhook

The package serves `POST .hooks/email-events/{provider}`. In your email
provider's dashboard, set the webhook URL to:

```
https://your-app.com/.hooks/email-events/{provider}
```

…where `{provider}` is `sendgrid`, `postmark`, `mailgun`, `ses`, or `resend`.

### 2. Listen for the event

In a service provider's `boot()` method:

```php
use Illuminate\Support\Facades\Event;
use STS\EmailEvents\EmailEvent;

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
MAIL_EVENTS_SENDGRID_VERIFICATION_KEY=...
```

### Mailgun

```
MAIL_EVENTS_MAILGUN_SIGNING_KEY=...   # falls back to MAILGUN_SECRET
```

### Amazon SES

SES delivers events through SNS. Subscribe an SNS topic to
`.hooks/email-events/ses`; the package verifies the SNS message signature and
automatically completes the subscription-confirmation handshake. No secret to
configure.

### Resend

```
MAIL_EVENTS_RESEND_SIGNING_SECRET=whsec_...
```

### Postmark

Postmark does not sign webhook payloads. Use HTTP basic auth (the default) or a
URL token:

```
MAIL_EVENTS_AUTH_USERNAME=...
MAIL_EVENTS_AUTH_PASSWORD=...
```

### Token or basic auth

Any provider can instead use a shared URL token or HTTP basic auth by setting
its `auth` to `token` or `basic`:

```
MAIL_EVENTS_AUTH_TOKEN=mysecrettoken
# then append ?auth=mysecrettoken to the webhook URL
```

Each provider's verification method is its `auth` key in
`config/email-events.php` — a built-in authorizer (`token`, `basic`,
`user-agent`) or a fully-qualified authorizer class. Providers default to
signature verification where the provider supports it.

## Invalid payloads

If a payload can't be turned into a valid event, the `on_invalid` config
setting decides what happens: `log` (default), `throw`, or `ignore`.

```
MAIL_EVENTS_ON_INVALID=log
```

## Optional persistence

So far the package is a pure event dispatcher. Enable persistence and it will
also **record every outbound email** and keep that record up to date as
webhook events arrive — correlated by provider message id — giving you a
queryable delivery history.

```
MAIL_EVENTS_PERSISTENCE=true
```

Publish and run the migration:

```bash
php artisan vendor:publish --tag=email-events.migrations
php artisan migrate
```

This creates an `email_messages` table. Each row tracks a message's
`status`, `bounce_type`, `sent_at`, and `last_event_at`. The model
(`STS\EmailEvents\Models\EmailMessage`) is swappable via the
`email-events.persistence.model` config key.

It ships query scopes for the common lookups — `delivered()`, `bounced()`,
`complained()`, `opened()`, `clicked()`, `sent()`, `accepted()`, `deferred()`,
`dropped()`, and the generic `withStatus()`:

```php
use STS\EmailEvents\Models\EmailMessage;

EmailMessage::bounced()->count();
EmailMessage::delivered()->where('sent_at', '>', now()->subDay())->get();
```

The package still dispatches `EmailEvent` in all modes — persistence is just a
first-party listener layered on top.

### Relating emails to your models

Recorded emails can be linked back to one of your own models — an `Order`, a
`User`, anything — so that model can list its own delivery history (great for
an admin activity feed that highlights bounces and complaints).

Add the `TracksEmailEvents` trait to a Mailable and call `relatedTo()`:

```php
use Illuminate\Mail\Mailable;
use STS\EmailEvents\Concerns\TracksEmailEvents;

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
use STS\EmailEvents\Concerns\HasEmailMessages;

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

### Multitenancy

In a multitenant app you'll often want every recorded email tagged with its
owning tenant — including emails that aren't tied to any `related` model — so a
tenant can see all of its delivery activity at once.

Register a tenant resolver, typically in a service provider:

```php
use STS\EmailEvents\Facades\EmailEvents;

EmailEvents::resolveTenantUsing(fn () => tenant());
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

## Configuration

The defaults work out of the box. To customize them — change the webhook path,
adjust per-provider settings, tweak persistence — publish the config file:

```bash
php artisan vendor:publish --tag=email-events.config
```

The webhook route is registered for you. To register it yourself instead — a
custom domain, prefix, or middleware — set `MAIL_EVENTS_REGISTER_ROUTE=false`
and call `EmailEvents::routes()` from your own route file.

## Custom providers

Register your own provider at runtime with a resolver closure:

```php
use STS\EmailEvents\Facades\EmailEvents;
use STS\EmailEvents\Provider;

EmailEvents::extend('myprovider', function (array $config) {
    return new Provider('myprovider', MyAdapter::class, fn ($request) => true);
});
```

An adapter implements `STS\EmailEvents\Contracts\Adapter` (extending
`STS\EmailEvents\Providers\AbstractAdapter` covers most of it).

## Upgrading

Coming from a 0.x release? See [UPGRADE.md](UPGRADE.md).

## License

MIT. See [LICENSE.md](LICENSE.md).

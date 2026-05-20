# Laravel Email Events

[![Latest Version on Packagist](https://img.shields.io/packagist/v/stechstudio/laravel-email-events.svg?style=flat-square)](https://packagist.org/packages/stechstudio/laravel-email-events)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE.md)

Your Laravel app sends mail through SendGrid, Postmark, Mailgun, Amazon SES, or
Resend. Each of those providers can POST webhooks back to you when something
happens to a message — a delivery, an open, a bounce, a complaint. But every
provider authenticates, shapes, and names those events differently.

This package accepts webhooks from any supported provider, **verifies** the
request, **normalizes** the payload into a single `EmailEvent`, and dispatches
it as a Laravel event. You listen for one event and react — no provider-specific
code in your app.

Optionally, it can also **record every outbound email** and keep that record
up to date as webhook events arrive, giving you a queryable delivery history.

## Supported providers

| Provider  | Signature verification |
| --------- | ---------------------- |
| SendGrid  | ECDSA (Signed Event Webhook) |
| Postmark  | — (use basic auth or a token) |
| Mailgun   | HMAC-SHA256 |
| Amazon SES| SNS message signature (x509) |
| Resend    | Svix HMAC-SHA256 |

## Requirements

- PHP 8.2+
- Laravel 12 or 13

## Installation

```bash
composer require stechstudio/laravel-email-events
```

Publish the config file:

```bash
php artisan vendor:publish --tag=email-events.config
```

## Quick start

### 1. Configure verification

Each provider verifies inbound webhooks differently. Set the relevant
credentials in your `.env` (see [Verification](#verification) below). For
example, for SendGrid:

```
MAIL_EVENTS_SENDGRID_VERIFICATION_KEY=<your SendGrid verification key>
```

### 2. Point your provider at the endpoint

The package registers `POST .hooks/email-events/{provider}` automatically. In
your provider's webhook settings, use:

```
https://your-app.com/.hooks/email-events/{provider}
```

…where `{provider}` is `sendgrid`, `postmark`, `mailgun`, `ses`, or `resend`.

To register the route yourself instead — e.g. to apply a custom domain,
prefix, or middleware — set `MAIL_EVENTS_REGISTER_ROUTE=false` and call
`EmailEvents::routes()` from your own route file.

### 3. Listen for events

```php
namespace App\Listeners;

use STS\EmailEvents\EmailEvent;

class HandleEmailEvent
{
    public function handle(EmailEvent $event): void
    {
        if ($event->isPermanent()) {
            // A hard bounce or a block — safe to suppress this address.
            MySuppressionList::add($event->getRecipient());
        }
    }
}
```

To process webhooks off the request cycle, make your listener implement
`Illuminate\Contracts\Queue\ShouldQueue` — Laravel will queue it for you.

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

## Verification

A provider's `auth` setting (in `config/email-events.php`) decides how its
webhooks are verified. It may name a built-in authorizer (`token`, `basic`,
`user-agent`) or a fully-qualified authorizer class. Providers default to
signature verification where the provider supports it.

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

### Token / basic auth

Any provider can instead use a shared URL token or HTTP basic auth by setting
its `auth` to `token` or `basic`:

```
MAIL_EVENTS_AUTH_TOKEN=mysecrettoken
# then append ?auth=mysecrettoken to the webhook URL
```

## Invalid payloads

If a payload can't be turned into a valid event, the `on_invalid` config
setting decides what happens: `log` (default), `throw`, or `ignore`.

```
MAIL_EVENTS_ON_INVALID=log
```

## Optional persistence

When enabled, the package records every outbound email and updates that record
as webhook events arrive — correlated by provider message id — giving you a
queryable delivery lifecycle.

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
$order->emailMessages()->where('status', 'bounced')->exists();
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
EmailMessage::forTenant($tenant)->where('status', 'bounced')->get();
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

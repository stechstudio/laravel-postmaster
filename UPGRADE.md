# Upgrading from `laravel-email-events`

This package was previously published as **`stechstudio/laravel-email-events`**.
It has been renamed to **`stechstudio/laravel-postmaster`** to reflect its
broader scope — it now records and tracks outbound mail, not just webhook
events. Version 1.0 is the first release under the new name.

`laravel-email-events` `0.14` remains installable and will keep working, but it
receives no further development. This guide covers everything needed to move
from `0.14` to `laravel-postmaster` `1.0`.

## 1. Swap the Composer package

```bash
composer remove stechstudio/laravel-email-events
composer require stechstudio/laravel-postmaster
```

## 2. Requirements

- **PHP 8.2+** is now required (previously 8.0).
- **Laravel 12 or 13.** Support for Laravel 8–11 has been dropped.

## 3. Rename map

Every package identifier changed from an `EmailEvents` / `email-events` /
`MAIL_EVENTS` form to a `Postmaster` / `postmaster` / `POSTMASTER` form.

| Concept | Old | New |
| --- | --- | --- |
| Namespace root | `STS\EmailEvents\` | `STS\Postmaster\` |
| Facade | `STS\EmailEvents\Facades\EmailEvents` | `STS\Postmaster\Facades\Postmaster` |
| Facade alias | `EmailEvents::` | `Postmaster::` |
| Service provider | `STS\EmailEvents\EmailEventsServiceProvider` | `STS\Postmaster\PostmasterServiceProvider` |
| Config file | `config/email-events.php` | `config/postmaster.php` |
| Config key | `config('email-events.*')` | `config('postmaster.*')` |
| Env prefix | `MAIL_EVENTS_*` | `POSTMASTER_*` |
| Webhook path | `.hooks/email-events/{provider}` | `.hooks/postmaster/{provider}` |
| Route name | `webhook.email-events` | `webhook.postmaster` |
| Console command | `email-events:prune-content` | `postmaster:prune-content` |

Find-and-replace across your application code:

- `STS\EmailEvents` → `STS\Postmaster`
- `MAIL_EVENTS_` → `POSTMASTER_` (in your `.env`)
- `email-events` → `postmaster` (config calls, the published config filename)

The provider auto-discovery and facade alias update themselves once the new
package is installed — no manual registration needed.

> **Webhook URLs change.** The default path moved from `.hooks/email-events/...`
> to `.hooks/postmaster/...`. Update the webhook URL in each provider's
> dashboard, or set `POSTMASTER_URL=.hooks/email-events` to keep the old path.

## 4. Provider namespaces

Provider-specific classes moved out of the flat `Adapters/` and `Auth/`
namespaces into a per-provider `Providers/` namespace:

| 0.x (`laravel-email-events`) | 1.0 (`laravel-postmaster`) |
| --- | --- |
| `STS\EmailEvents\Adapters\SendGrid` | `STS\Postmaster\Providers\SendGrid\Adapter` |
| `STS\EmailEvents\Adapters\Postmark` | `STS\Postmaster\Providers\Postmark\Adapter` |
| `STS\EmailEvents\Adapters\Mailgun` | `STS\Postmaster\Providers\Mailgun\Adapter` |
| `STS\EmailEvents\Adapters\AbstractAdapter` | `STS\Postmaster\Providers\AbstractAdapter` |
| `STS\EmailEvents\Auth\MailgunSignatureAuth` | `STS\Postmaster\Providers\Mailgun\SignatureAuth` |

`TokenAuth`, `BasicHttpAuth`, and `UserAgentAuth` keep their place under the
`Auth\` namespace (now `STS\Postmaster\Auth\`). The normalized event class is
now `STS\Postmaster\EmailEvent`.

If you only reference providers through the config file and listen for
`EmailEvent`, no class references need to change beyond the namespace root —
just re-publish the config.

## 5. Configuration

The config file has been restructured. Re-publish it and re-apply your
settings:

```bash
php artisan vendor:publish --tag=postmaster.config --force
```

Key changes:

- Per-provider credentials now live under each provider in the `providers`
  array, rather than as top-level keys. The old top-level `signature_key` is
  gone; Mailgun's signing key is now `providers.mailgun.signing_key`.
- New `ses` and `resend` providers.
- Each provider's `auth` now defaults to **signature verification** where the
  provider supports it (previously everything defaulted to `token`). Set the
  relevant verification credentials, or override `auth` back to `token` /
  `basic`. See the README's Verification section.
- A provider's `auth` may now be a fully-qualified authorizer class, not only
  a named key.
- New optional `persistence` block (off by default).

## 6. API changes

- **`Postmaster::extend()`** now takes `(string $name, Closure $resolver)`
  instead of `(string $name, string $adapter, callable $authorizer)`. The
  resolver receives the config array and returns a `Provider`.
- **`Provider::authorize()`** (which threw on failure) has been replaced by
  **`Provider::passesAuthorization()`**, which returns a `bool`. Authorization
  now runs as the `VerifyWebhook` route middleware.
- The webhook route is now registered automatically by the package and handled
  by `WebhookController`. Remove your manual `Postmaster::routes()` call — or
  set `POSTMASTER_REGISTER_ROUTE=false` to keep registering it yourself.
- `EmailEvent`'s magic `__call` passthrough was replaced with explicit methods.
  Behavior is identical; static analysis and IDE autocomplete now work.

## 7. New, non-breaking additions

These are additive — no action required, but worth knowing:

- `EmailEvent::getBounceType()` / `isPermanent()` — normalized bounce severity.
- `EmailEvent::getDate()` — the timestamp as a `DateTimeImmutable`.
- `toArray()` gained `date` and `bounceType` keys.
- `on_invalid` config — control what happens to unparseable payloads.
- Optional persistence: record outbound mail, relate it to your models, scope
  it per tenant, and store full message content. See the README.

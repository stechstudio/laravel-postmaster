# Upgrading from 0.x to 1.0

Version 1.0 is a significant release: it adds two providers, per-provider
signature verification, an optional persistence layer, and a reorganized
internal architecture. This guide covers the breaking changes.

## Requirements

- **PHP 8.2+** is now required (previously 8.0).
- **Laravel 12 or 13.** Support for Laravel 8–11 has been dropped.

## Namespaces

Provider-specific classes moved out of the flat `Adapters/` and `Auth/`
namespaces into a per-provider `Providers/` namespace:

| 0.x | 1.0 |
| --- | --- |
| `STS\EmailEvents\Adapters\SendGrid` | `STS\EmailEvents\Providers\SendGrid\Adapter` |
| `STS\EmailEvents\Adapters\Postmark` | `STS\EmailEvents\Providers\Postmark\Adapter` |
| `STS\EmailEvents\Adapters\Mailgun` | `STS\EmailEvents\Providers\Mailgun\Adapter` |
| `STS\EmailEvents\Adapters\AbstractAdapter` | `STS\EmailEvents\Providers\AbstractAdapter` |
| `STS\EmailEvents\Auth\MailgunSignatureAuth` | `STS\EmailEvents\Providers\Mailgun\SignatureAuth` |

`STS\EmailEvents\Auth\TokenAuth`, `BasicHttpAuth`, and `UserAgentAuth` are
unchanged. `STS\EmailEvents\EmailEvent` is unchanged.

If you only reference providers through the config file and listen for
`EmailEvent`, no code changes are needed — just re-publish the config.

## Configuration

The config file has been restructured. Re-publish it and re-apply your
settings:

```bash
php artisan vendor:publish --tag=email-events.config --force
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

## API changes

- **`EmailEvents::extend()`** now takes `(string $name, Closure $resolver)`
  instead of `(string $name, string $adapter, callable $authorizer)`. The
  resolver receives the config array and returns a `Provider`.
- **`Provider::authorize()`** (which threw on failure) has been replaced by
  **`Provider::passesAuthorization()`**, which returns a `bool`. Authorization
  now runs as the `VerifyWebhook` route middleware.
- The webhook route is now handled by `WebhookController` instead of an inline
  closure. If you registered the route yourself, use `EmailEvents::routes()`.
- `EmailEvent`'s magic `__call` passthrough was replaced with explicit methods.
  Behavior is identical; static analysis and IDE autocomplete now work.

## New, non-breaking additions

These are additive — no action required, but worth knowing:

- `EmailEvent::getBounceType()` / `isPermanent()` — normalized bounce severity.
- `EmailEvent::getDate()` — the timestamp as a `DateTimeImmutable`.
- `toArray()` gained `date` and `bounceType` keys.
- `on_invalid` config — control what happens to unparseable payloads.

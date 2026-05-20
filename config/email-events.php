<?php

return [

    /*
     * The path the webhook route is registered at. The provider name is
     * appended, e.g. ".hooks/email-events/sendgrid".
     */
    'url' => env('MAIL_EVENTS_URL', '.hooks/email-events'),

    /*
     * Whether the package registers its webhook route automatically. Disable
     * this to register the route yourself with EmailEvents::routes() — for
     * example to apply a custom domain, prefix, or middleware.
     */
    'register_route' => env('MAIL_EVENTS_REGISTER_ROUTE', true),

    /*
     * What to do with a webhook payload that no adapter can turn into a valid
     * event: "log" a warning, "throw" an exception, or silently "ignore" it.
     */
    'on_invalid' => env('MAIL_EVENTS_ON_INVALID', 'log'),

    /*
     * Shared credentials for the "token" and "basic" authorizers below.
     */
    'token' => env('MAIL_EVENTS_AUTH_TOKEN'),
    'token_parameter' => env('MAIL_EVENTS_AUTH_TOKEN_PARAM', 'auth'),
    'basic_username' => env('MAIL_EVENTS_AUTH_USERNAME'),
    'basic_password' => env('MAIL_EVENTS_AUTH_PASSWORD'),

    /*
     * Named, provider-agnostic authorizers. A provider's "auth" setting may
     * reference one of these keys, or give a fully-qualified authorizer class
     * directly (e.g. a provider-specific signature verifier).
     */
    'authorizers' => [
        'token'      => \STS\EmailEvents\Auth\TokenAuth::class,
        'basic'      => \STS\EmailEvents\Auth\BasicHttpAuth::class,
        'user-agent' => \STS\EmailEvents\Auth\UserAgentAuth::class,
    ],

    /*
     * Registered providers. Each maps an adapter (parses webhook payloads)
     * and an authorizer (verifies inbound requests). Signature verification
     * is the default where the provider supports it.
     */
    'providers' => [

        'sendgrid' => [
            'adapter' => \STS\EmailEvents\Providers\SendGrid\Adapter::class,
            'auth'    => env('MAIL_EVENTS_SENDGRID_AUTH', \STS\EmailEvents\Providers\SendGrid\SignatureAuth::class),
            // The base64 "Verification Key" from SendGrid's Signed Event Webhook settings.
            'verification_key' => env('MAIL_EVENTS_SENDGRID_VERIFICATION_KEY'),
        ],

        'postmark' => [
            'adapter' => \STS\EmailEvents\Providers\Postmark\Adapter::class,
            // Postmark does not sign webhook payloads; use basic auth or a token.
            'auth'    => env('MAIL_EVENTS_POSTMARK_AUTH', 'basic'),
        ],

        'mailgun' => [
            'adapter' => \STS\EmailEvents\Providers\Mailgun\Adapter::class,
            'auth'    => env('MAIL_EVENTS_MAILGUN_AUTH', \STS\EmailEvents\Providers\Mailgun\SignatureAuth::class),
            'signing_key' => env('MAIL_EVENTS_MAILGUN_SIGNING_KEY', env('MAILGUN_SECRET')),
        ],

        'ses' => [
            'adapter' => \STS\EmailEvents\Providers\Ses\Adapter::class,
            // SES delivers via SNS; the SNS message is verified against its x509 cert.
            'auth'    => env('MAIL_EVENTS_SES_AUTH', \STS\EmailEvents\Providers\Ses\SignatureAuth::class),
        ],

        'resend' => [
            'adapter' => \STS\EmailEvents\Providers\Resend\Adapter::class,
            'auth'    => env('MAIL_EVENTS_RESEND_AUTH', \STS\EmailEvents\Providers\Resend\SignatureAuth::class),
            // The "whsec_..." signing secret from Resend's webhook settings.
            'signing_secret' => env('MAIL_EVENTS_RESEND_SIGNING_SECRET'),
        ],

    ],

    /*
     * Optional persistence. When enabled, the package records every outbound
     * email and updates that record as webhook events arrive, correlated by
     * provider message id. Off by default — the package works as a pure event
     * dispatcher without it.
     */
    'persistence' => [
        'enabled' => env('MAIL_EVENTS_PERSISTENCE', false),
        'model'   => \STS\EmailEvents\Models\EmailMessage::class,
        'table'   => 'email_messages',

        /*
         * Connection for the email messages table. Leave null for the default
         * connection. Database-per-tenant apps should point this at a shared
         * connection so tenant-less webhooks can always find the record.
         */
        'connection' => env('MAIL_EVENTS_PERSISTENCE_CONNECTION'),

        /*
         * Multitenancy. The tenant column stores the owning tenant's key.
         * Populate it per-send with a Mailable's forTenant(), or globally via
         * EmailEvents::resolveTenantUsing() in a service provider. Set
         * tenant_model to enable the EmailMessage::tenant() relationship.
         */
        'tenant_column' => 'tenant_id',
        'tenant_model'  => null,
    ],

];

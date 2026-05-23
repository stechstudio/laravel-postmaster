<?php

return [

    /*
     * The path the webhook route is registered at. The provider name is
     * appended, e.g. "webhooks/postmaster/sendgrid".
     */
    'url' => env('POSTMASTER_URL', 'webhooks/postmaster'),

    /*
     * Whether the package registers its webhook route automatically. Disable
     * this to register the route yourself with Postmaster::routes() — for
     * example to apply a custom domain, prefix, or middleware.
     */
    'register_route' => env('POSTMASTER_REGISTER_ROUTE', true),

    /*
     * What to do with a webhook payload that no adapter can turn into a valid
     * event: "log" a warning, "throw" an exception, or silently "ignore" it.
     */
    'on_invalid' => env('POSTMASTER_ON_INVALID', 'log'),

    /*
     * Outbound delivery mode:
     *
     *   "normal"   — mail is delivered as usual.
     *   "sandbox"  — mail is intercepted and never handed to the transport,
     *                but (with persistence enabled) is still recorded with a
     *                "sandbox" status, so it shows up in your app's email
     *                history without anything actually being sent. Handy in a
     *                staging environment.
     *   "redirect" — reserved for a future release.
     *
     * Sandbox is most useful with persistence (POSTMASTER_PERSISTENCE=true);
     * without it the package can still intercept the send, but nothing is
     * recorded — at which point Laravel's "log" mailer is the simpler tool.
     */
    'delivery' => env('POSTMASTER_DELIVERY', 'normal'),

    /*
     * When true, the package refuses to send to any address on its
     * suppression list. The attempt is still recorded (status: blocked) so
     * it shows up in the dashboard, but the message is never handed to the
     * mailer. Off by default — apps that want this safety net opt in.
     *
     * Needs the persistence layer (suppression lives there).
     */
    'block_suppressed' => env('POSTMASTER_BLOCK_SUPPRESSED', false),

    /*
     * Shared credentials for the "token" and "basic" authorizers below.
     */
    'token' => env('POSTMASTER_AUTH_TOKEN'),
    'token_parameter' => env('POSTMASTER_AUTH_TOKEN_PARAM', 'auth'),
    'basic_username' => env('POSTMASTER_AUTH_USERNAME'),
    'basic_password' => env('POSTMASTER_AUTH_PASSWORD'),

    /*
     * Named, provider-agnostic authorizers. A provider's "auth" setting may
     * reference one of these keys, or give a fully-qualified authorizer class
     * directly (e.g. a provider-specific signature verifier).
     */
    'authorizers' => [
        'token'      => \STS\Postmaster\Auth\TokenAuth::class,
        'basic'      => \STS\Postmaster\Auth\BasicHttpAuth::class,
        'user-agent' => \STS\Postmaster\Auth\UserAgentAuth::class,
    ],

    /*
     * Registered providers. Each maps an adapter (parses webhook payloads)
     * and an authorizer (verifies inbound requests). Signature verification
     * is the default where the provider supports it.
     */
    'providers' => [

        'sendgrid' => [
            'adapter' => \STS\Postmaster\Providers\SendGrid\Adapter::class,
            'auth'    => env('POSTMASTER_SENDGRID_AUTH', \STS\Postmaster\Providers\SendGrid\SignatureAuth::class),
            // The base64 "Verification Key" from SendGrid's Signed Event Webhook settings.
            'verification_key' => env('POSTMASTER_SENDGRID_VERIFICATION_KEY'),
        ],

        'postmark' => [
            'adapter' => \STS\Postmaster\Providers\Postmark\Adapter::class,
            // Postmark does not sign webhook payloads; use basic auth or a token.
            'auth'    => env('POSTMASTER_POSTMARK_AUTH', 'basic'),
        ],

        'mailgun' => [
            'adapter' => \STS\Postmaster\Providers\Mailgun\Adapter::class,
            'auth'    => env('POSTMASTER_MAILGUN_AUTH', \STS\Postmaster\Providers\Mailgun\SignatureAuth::class),
            'signing_key' => env('POSTMASTER_MAILGUN_SIGNING_KEY', env('MAILGUN_SECRET')),
        ],

        'ses' => [
            'adapter' => \STS\Postmaster\Providers\Ses\Adapter::class,
            // SES delivers via SNS; the SNS message is verified against its x509 cert.
            'auth'    => env('POSTMASTER_SES_AUTH', \STS\Postmaster\Providers\Ses\SignatureAuth::class),
        ],

        'resend' => [
            'adapter' => \STS\Postmaster\Providers\Resend\Adapter::class,
            'auth'    => env('POSTMASTER_RESEND_AUTH', \STS\Postmaster\Providers\Resend\SignatureAuth::class),
            // The "whsec_..." signing secret from Resend's webhook settings.
            'signing_secret' => env('POSTMASTER_RESEND_SIGNING_SECRET'),
        ],

    ],

    /*
     * Optional persistence. When enabled, the package records every outbound
     * email and updates that record as webhook events arrive, correlated by
     * provider message id. On by default — publishing and running the
     * migrations is all that's needed. Set this to false to run the package
     * as a pure event dispatcher with no database writes.
     */
    'persistence' => [
        'enabled' => env('POSTMASTER_PERSISTENCE', true),
        'model'   => \STS\Postmaster\Models\EmailMessage::class,
        'table'   => 'email_messages',

        /*
         * Connection for the email messages table. Leave null for the default
         * connection. Database-per-tenant apps should point this at a shared
         * connection so tenant-less webhooks can always find the record.
         */
        'connection' => env('POSTMASTER_PERSISTENCE_CONNECTION'),

        /*
         * Multitenancy. The tenant column stores the owning tenant's key.
         * Populate it per-send with a Mailable's forTenant(), or globally via
         * Postmaster::resolveTenantUsing() in a service provider. Set
         * tenant_model to enable the EmailMessage::tenant() relationship.
         */
        'tenant_column' => 'tenant_id',
        'tenant_model'  => null,

        /*
         * Store the full message content (sender, recipients, bodies, and
         * attachment filenames) on each record. Off by default: message
         * bodies are large and may contain personal data or secrets such as
         * password-reset links.
         */
        'store_content' => env('POSTMASTER_STORE_CONTENT', false),

        /*
         * Days to retain stored content before the postmaster:prune-content
         * command purges it (the record itself is kept). The command is
         * scheduled automatically when this is set. Null disables pruning.
         */
        'prune_content_after_days' => env('POSTMASTER_PRUNE_CONTENT_AFTER_DAYS'),

        /*
         * Record a full delivery timeline. With this on, the initial send and
         * every webhook event are also stored as their own rows in the
         * email_message_events table — so a message keeps its complete
         * history, including repeated opens and clicks, alongside the latest
         * status on the summary record.
         *
         * On by default whenever persistence is enabled. Set
         * POSTMASTER_RECORD_EVENTS=false to keep only the summary record.
         */
        'record_events' => env('POSTMASTER_RECORD_EVENTS', true),
        'events_table'  => 'email_message_events',
        'event_model'   => \STS\Postmaster\Models\EmailMessageEvent::class,

        /*
         * Days to retain timeline events before the postmaster:prune-events
         * command deletes them (whole rows, summary records untouched). The
         * command is scheduled automatically when this is set. Null disables
         * pruning.
         */
        'prune_events_after_days' => env('POSTMASTER_PRUNE_EVENTS_AFTER_DAYS'),

        /*
         * Track per-address deliverability. With this on, the email_addresses
         * table keeps one row per recipient with a current status — active or
         * suppressed — so "should I send to this address?" is a single lookup
         * rather than a scan of message history. An address is suppressed on
         * a hard bounce, a complaint, or a drop.
         *
         * Suppression is global, never per tenant: providers suppress on
         * their side regardless of which tenant sent the mail.
         *
         * On by default whenever persistence is enabled. Set
         * POSTMASTER_TRACK_ADDRESSES=false to disable it.
         */
        'track_addresses' => env('POSTMASTER_TRACK_ADDRESSES', true),
        'addresses_table' => 'email_addresses',
        'address_model'   => \STS\Postmaster\Models\EmailAddress::class,
    ],

    /*
     * The superadmin dashboard — a gated, cross-tenant view of all recorded
     * email activity. Off by default. When enabled, also register an
     * authorization gate with Postmaster::auth() in a service provider;
     * without one, access is allowed only in the local environment.
     */
    'dashboard' => [
        'enabled'    => env('POSTMASTER_DASHBOARD', false),
        'path'       => env('POSTMASTER_DASHBOARD_PATH', 'postmaster'),
        'middleware' => ['web'],
    ],

];

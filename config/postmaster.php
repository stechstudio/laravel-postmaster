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
     * Process inbound webhooks on the queue instead of inline. When false
     * (the default), the controller parses the payload, dispatches every
     * resulting EmailEvent, and waits for all listeners to finish before
     * responding to the provider. At low volume that's fine. At higher
     * volume — or with slow database / heavy app listeners — set this true
     * so the controller accepts the request, queues a ProcessWebhook job,
     * and returns 202 immediately. Webhook signature verification still
     * happens inline.
     */
    'queue_webhooks' => env('POSTMASTER_QUEUE_WEBHOOKS', false),

    /*
     * Optional queue connection / queue name for the ProcessWebhook job.
     * Null on either falls back to the application's default. Useful for
     * isolating webhook traffic onto its own queue so a backlog elsewhere
     * doesn't delay event handling.
     */
    'queue_connection' => env('POSTMASTER_QUEUE_CONNECTION'),
    'queue_name'       => env('POSTMASTER_QUEUE_NAME'),

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
            'sync'    => \STS\Postmaster\Providers\SendGrid\SuppressionSync::class,
            // The base64 "Verification Key" from SendGrid's Signed Event Webhook settings.
            'verification_key' => env('POSTMASTER_SENDGRID_VERIFICATION_KEY'),
            // SendGrid API key used by the suppression sync. Falls back to
            // SENDGRID_API_KEY so apps already using Laravel's SendGrid
            // mailer don't have to add a second key.
            'api_key' => env('POSTMASTER_SENDGRID_API_KEY', env('SENDGRID_API_KEY')),
        ],

        'postmark' => [
            'adapter' => \STS\Postmaster\Providers\Postmark\Adapter::class,
            // Postmark does not sign webhook payloads; use basic auth or a token.
            'auth'    => env('POSTMASTER_POSTMARK_AUTH', 'basic'),
            'sync'    => \STS\Postmaster\Providers\Postmark\SuppressionSync::class,
            'server_token' => env('POSTMASTER_POSTMARK_SERVER_TOKEN', env('POSTMARK_TOKEN')),
        ],

        'mailgun' => [
            'adapter' => \STS\Postmaster\Providers\Mailgun\Adapter::class,
            'auth'    => env('POSTMASTER_MAILGUN_AUTH', \STS\Postmaster\Providers\Mailgun\SignatureAuth::class),
            'sync'    => \STS\Postmaster\Providers\Mailgun\SuppressionSync::class,
            'signing_key' => env('POSTMASTER_MAILGUN_SIGNING_KEY', env('MAILGUN_SECRET')),
            'api_key'     => env('POSTMASTER_MAILGUN_API_KEY', env('MAILGUN_SECRET')),
            'domain'      => env('POSTMASTER_MAILGUN_DOMAIN', env('MAILGUN_DOMAIN')),
        ],

        'ses' => [
            'adapter' => \STS\Postmaster\Providers\Ses\Adapter::class,
            // SES delivers via SNS; the SNS message is verified against its x509 cert.
            'auth'    => env('POSTMASTER_SES_AUTH', \STS\Postmaster\Providers\Ses\SignatureAuth::class),
            'sync'    => \STS\Postmaster\Providers\Ses\SuppressionSync::class,
            // SES uses the standard AWS credential chain — the Laravel config
            // is read directly, no extra env. Override the region here when
            // SES sends from somewhere other than the app's default region.
            'region'  => env('POSTMASTER_SES_REGION', env('AWS_DEFAULT_REGION', 'us-east-1')),
        ],

        'resend' => [
            'adapter' => \STS\Postmaster\Providers\Resend\Adapter::class,
            'auth'    => env('POSTMASTER_RESEND_AUTH', \STS\Postmaster\Providers\Resend\SignatureAuth::class),
            'sync'    => \STS\Postmaster\Providers\Resend\SuppressionSync::class,
            // The "whsec_..." signing secret from Resend's webhook settings.
            'signing_secret' => env('POSTMASTER_RESEND_SIGNING_SECRET'),
            'api_key'        => env('POSTMASTER_RESEND_API_KEY', env('RESEND_KEY')),
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
        'enabled'        => env('POSTMASTER_PERSISTENCE', true),
        'message_model'  => \STS\Postmaster\Models\EmailMessage::class,
        'messages_table' => 'email_messages',

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
         * scheduled automatically when this is set. Set to 0 or null to
         * disable pruning entirely.
         *
         * Default is 30 days — stored content can contain personal data or
         * secrets, so the default is short on purpose.
         */
        'prune_content_after_days' => env('POSTMASTER_PRUNE_CONTENT_AFTER_DAYS', 30),

        /*
         * Record a full delivery timeline. With this on, the initial send and
         * every webhook event are also stored as their own rows in the
         * email_activity table — so a message keeps its complete
         * history, including repeated opens and clicks, alongside the latest
         * status on the summary record.
         *
         * On by default whenever persistence is enabled. Set
         * POSTMASTER_RECORD_EVENTS=false to keep only the summary record.
         */
        'record_events'  => env('POSTMASTER_RECORD_EVENTS', true),
        'activity_model' => \STS\Postmaster\Models\EmailActivity::class,
        'activity_table' => 'email_activity',

        /*
         * Days to retain *routine* timeline events (sent, accepted, deferred,
         * delivered, opened, clicked, sandboxed, blocked) before the daily
         * postmaster:prune command deletes them. Operational debug window;
         * keeps the events table from growing unbounded. Set to 0 or null
         * to disable.
         */
        'prune_routine_activity_after_days' => env('POSTMASTER_PRUNE_ROUTINE_ACTIVITY_AFTER_DAYS', 90),

        /*
         * Days to retain *failure* timeline events (bounced, dropped,
         * complained) before the daily postmaster:prune command deletes
         * them. Failures keep their raw provider diagnostic for forensic
         * use — useful when investigating why a domain is misbehaving
         * months later — so they're kept much longer than routine events.
         * Set to 0 or null to disable.
         */
        'prune_failed_activity_after_days' => env('POSTMASTER_PRUNE_FAILED_ACTIVITY_AFTER_DAYS', 365),

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
        'address_model'   => \STS\Postmaster\Models\EmailAddress::class,
        'addresses_table' => 'email_addresses',
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

        /*
         * Cooldown between Resend button clicks for the same message,
         * in seconds. Prevents double-click / rapid-fire mistakes from
         * sending the same message multiple times. Set to 0 to disable.
         */
        'resend_throttle_seconds' => env('POSTMASTER_DASHBOARD_RESEND_THROTTLE_SECONDS', 60),
    ],

];

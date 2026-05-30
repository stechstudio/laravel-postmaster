<?php

namespace STS\Postmaster;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use STS\Postmaster\Http\Controllers\WebhookController;
use STS\Postmaster\Http\Middleware\VerifyWebhook;
use STS\Postmaster\Models\EmailActivity;
use STS\Postmaster\Models\EmailAddress;
use STS\Postmaster\Models\EmailMessage;
use STS\Postmaster\Support\OutboundMetadata;
use Symfony\Component\Mime\Email;
use Throwable;

class Postmaster
{
    /**
     * @var array
     */
    protected $config;

    /**
     * @var ProviderRegistry
     */
    protected $registry;

    /**
     * Resolves the current tenant when recording outbound mail. Registered
     * by the consuming app, typically in a service provider.
     *
     * @var Closure|null
     */
    protected $tenantResolver;

    /**
     * Resolves the recipient model (e.g. the User) when an outbound email is
     * recorded and the Mailable didn't declare one. Receives the to-address
     * and must return a Model or null. Registered by the consuming app.
     *
     * @var Closure|null
     */
    protected $recipientResolver;

    /**
     * Authorizes access to the dashboard. Registered by the consuming app,
     * typically in a service provider.
     *
     * @var Closure|null
     */
    protected $authCallback;

    /**
     * Postmaster constructor.
     *
     * @param $config
     */
    public function __construct( $config )
    {
        $this->config = $config;
        $this->registry = new ProviderRegistry($config);
    }

    /**
     * @return ProviderRegistry
     */
    public function registry()
    {
        return $this->registry;
    }

    /**
     * @param string $name
     *
     * @return Provider
     */
    public function provider( $name )
    {
        return $this->registry->get($name);
    }

    /**
     * Register a custom provider. The resolver receives the package config
     * array and must return a Provider instance.
     *
     * @param string  $name
     * @param Closure $resolver
     *
     * @return $this
     */
    public function extend( $name, Closure $resolver )
    {
        $this->registry->extend($name, $resolver);

        return $this;
    }

    /**
     * Register a resolver for the current tenant. The resolver is invoked
     * when persistence records an outbound email; it may return a tenant
     * model or its key (or null). Called lazily, so a closure that reaches
     * for the active tenant resolves correctly per request or queued job.
     *
     * An explicit Mailable forTenant() call takes precedence over this.
     *
     * @param Closure $resolver
     *
     * @return $this
     */
    public function resolveTenantUsing( Closure $resolver )
    {
        $this->tenantResolver = $resolver;

        return $this;
    }

    /**
     * Resolve the current tenant key via the registered resolver, or null
     * if none is registered or it yields nothing.
     *
     * @return int|string|null
     */
    public function resolveTenant()
    {
        if ($this->tenantResolver === null) {
            return null;
        }

        $tenant = call_user_func($this->tenantResolver);

        if ($tenant instanceof Model) {
            $tenant = $tenant->getKey();
        }

        return $tenant;
    }

    /**
     * Register a resolver for the recipient model — typically the User the
     * email is being sent to. Invoked when recording an outbound email if
     * the Mailable's Tracking didn't already declare one. The resolver
     * receives the to-address and may return a Model (or null).
     *
     * Resolves once per email, lazily, so a closure that hits the database
     * (e.g. User::firstWhere('email', $address)) is fine.
     *
     * @param Closure $resolver
     *
     * @return $this
     */
    public function resolveRecipientUsing( Closure $resolver )
    {
        $this->recipientResolver = $resolver;

        return $this;
    }

    /**
     * Shortcut for the common "look up the recipient model by its email
     * column" case. Equivalent to:
     *
     *   Postmaster::resolveRecipientUsing(
     *       fn ($address) => User::firstWhere('email', $address)
     *   );
     *
     * The address is normalized (lower-cased, trimmed) the same way it is
     * stored, so a webhook for "Alice@Example.com" still matches the row
     * saved as "alice@example.com".
     *
     * @param class-string<Model> $class  The recipient model class.
     * @param string              $column The column on that model to match
     *                                    the address against.
     *
     * @return $this
     */
    public function resolveRecipientByEmail( string $class, string $column = 'email' )
    {
        return $this->resolveRecipientUsing(
            fn ($address) => $class::query()
                ->where($column, EmailAddress::normalize((string) $address))
                ->first()
        );
    }

    /**
     * Resolve the recipient model for the given address via the registered
     * resolver, or null if none is registered or the resolver yields nothing.
     *
     * @param string|null $address
     *
     * @return Model|null
     */
    public function resolveRecipient( $address )
    {
        if ($this->recipientResolver === null || empty($address)) {
            return null;
        }

        $recipient = call_user_func($this->recipientResolver, $address);

        return $recipient instanceof Model ? $recipient : null;
    }

    /**
     * Set the application's tenant model class at runtime, so the tenant()
     * relationship on EmailMessage and the dashboard's tenant labels work
     * without having to publish the config file just for this one value.
     *
     * @param string $class
     *
     * @return $this
     */
    public function useTenantModel( string $class )
    {
        config(['postmaster.persistence.tenant_model' => $class]);

        return $this;
    }

    /**
     * Swap in a custom EmailMessage model at runtime. Equivalent to setting
     * postmaster.persistence.model in the config; useful when an app keeps
     * the config un-published and configures everything from a service
     * provider.
     *
     * @param string $class
     *
     * @return $this
     */
    public function useEmailMessageModel( string $class )
    {
        config(['postmaster.persistence.model' => $class]);

        return $this;
    }

    /**
     * Swap in a custom EmailActivity model at runtime.
     *
     * @param string $class
     *
     * @return $this
     */
    public function useEmailActivityModel( string $class )
    {
        config(['postmaster.persistence.activity_model' => $class]);

        return $this;
    }

    /**
     * Swap in a custom EmailAddress model at runtime.
     *
     * @param string $class
     *
     * @return $this
     */
    public function useEmailAddressModel( string $class )
    {
        config(['postmaster.persistence.address_model' => $class]);

        return $this;
    }

    /**
     * Build a callback that associates a message with the given model.
     *
     * Pass it to any message exposing withSymfonyMessage() — a Mailable or a
     * notification's MailMessage. The relatedTo() trait methods use this
     * under the hood.
     *
     * @param Model $model
     *
     * @return Closure
     */
    public function relatedTo( Model $model )
    {
        return function (Email $message) use ($model) {
            $message->getHeaders()->addTextHeader(
                OutboundMetadata::HEADER_RELATED_TYPE, $model->getMorphClass()
            );

            $message->getHeaders()->addTextHeader(
                OutboundMetadata::HEADER_RELATED_ID, (string) $model->getKey()
            );
        };
    }

    /**
     * Build a callback that records the recipient model — the person the
     * email is for — separate from relatedTo(). Applies to the primary
     * To recipient on a multi-recipient send. Takes precedence over the
     * resolveRecipientUsing() resolver for that row.
     *
     * @param Model $model
     *
     * @return Closure
     */
    public function forRecipient( Model $model )
    {
        return function (Email $message) use ($model) {
            $message->getHeaders()->addTextHeader(
                OutboundMetadata::HEADER_RECIPIENT_TYPE, $model->getMorphClass()
            );

            $message->getHeaders()->addTextHeader(
                OutboundMetadata::HEADER_RECIPIENT_ID, (string) $model->getKey()
            );
        };
    }

    /**
     * Build a callback that records the recipient model *per address* for a
     * multi-recipient send. The map keys are email addresses (case-
     * insensitive); the values are Model instances. Addresses not in the
     * map fall through to the resolveRecipientUsing() resolver.
     *
     * @param array<string, Model> $map
     *
     * @return Closure
     */
    public function forRecipients( array $map )
    {
        $encoded = [];

        foreach ($map as $address => $model) {
            if (! $model instanceof Model) {
                continue;
            }

            $encoded[EmailAddress::normalize((string) $address)] = [
                $model->getMorphClass(),
                (string) $model->getKey(),
            ];
        }

        return function (Email $message) use ($encoded) {
            if ($encoded === []) {
                return;
            }

            $message->getHeaders()->addTextHeader(
                OutboundMetadata::HEADER_RECIPIENT_MAP,
                base64_encode(json_encode($encoded))
            );
        };
    }

    /**
     * Build a callback that associates a message with the given tenant.
     *
     * @param Model|int|string $tenant A tenant model or its key.
     *
     * @return Closure
     */
    public function forTenant( $tenant )
    {
        $key = $tenant instanceof Model ? $tenant->getKey() : $tenant;

        return function (Email $message) use ($key) {
            $message->getHeaders()->addTextHeader(
                OutboundMetadata::HEADER_TENANT, (string) $key
            );
        };
    }

    /**
     * Build a callback that overrides content storage for a single message,
     * regardless of the postmaster.persistence.store_content setting.
     *
     * @param bool $store
     *
     * @return Closure
     */
    public function storeContent( bool $store )
    {
        return function (Email $message) use ($store) {
            $message->getHeaders()->addTextHeader(
                OutboundMetadata::HEADER_STORE_CONTENT, $store ? '1' : '0'
            );
        };
    }

    /**
     * Build a callback that records the given EmailMessage (or its id) as the
     * original that the current send is a resend of. Postmaster::resend() and
     * the dashboard's Resend button use this themselves; this builder is for
     * app code that wants to declare a resend link from its own Mailable
     * (typically via Tracking::resentFrom).
     *
     * @param \STS\Postmaster\Models\EmailMessage|int $message
     *
     * @return Closure
     */
    public function resentFrom( EmailMessage|int $message )
    {
        $id = $message instanceof EmailMessage ? $message->getKey() : $message;

        return function (Email $email) use ($id) {
            $email->getHeaders()->addTextHeader(
                OutboundMetadata::HEADER_RESENT_FROM, (string) $id
            );
        };
    }

    /**
     * Replay a previously recorded EmailMessage through the configured
     * mailer, preserving every aspect of the original we can reconstruct:
     * sender, To/Cc/Bcc envelope, subject, html and text bodies, related /
     * recipient / tenant context, and tags (plus a `resent` tag of its own).
     * The new send is linked back to the original via resent_from_id, which
     * the dashboard's chain card walks to show the full retry history.
     *
     * Requires the original to have stored content. Attachments are not
     * restored — the package only persists their filenames, never their
     * bytes.
     *
     * Throws \RuntimeException when there's no stored content to replay.
     *
     * @param \STS\Postmaster\Models\EmailMessage|int $message
     *
     * @return \Illuminate\Mail\SentMessage|null  Whatever Mail::send() returns.
     */
    public function resend( EmailMessage|int $message )
    {
        $record = $message instanceof EmailMessage
            ? $message
            : $this->emailMessageModel()->newQuery()->withoutGlobalScopes()->findOrFail($message);

        if (! $record->html_body && ! $record->text_body) {
            throw new \RuntimeException(
                "Can't resend email message #{$record->getKey()}: no stored content. "
                ."Enable POSTMASTER_STORE_CONTENT before the original send so its body is captured."
            );
        }

        return Mail::send(new \STS\Postmaster\Mail\ResentMessage($record));
    }

    /**
     * A fresh instance of the configured (swappable) email message model.
     *
     * @return EmailMessage
     */
    protected function emailMessageModel()
    {
        $class = config('postmaster.persistence.model', \STS\Postmaster\Models\EmailMessage::class);

        return new $class;
    }

    /**
     * Register the webhook route. Call this from the consuming app's route
     * file. Authorization runs as middleware ahead of the controller.
     */
    public function routes()
    {
        Route::post($this->config['url'] . '/{provider}', WebhookController::class)
            ->middleware(VerifyWebhook::class)
            ->name('webhook.postmaster');
    }

    /**
     * Whether the given recipient address is currently suppressed. Use this
     * as a pre-send check. An address never seen is treated as sendable.
     *
     * Requires the optional persistence layer.
     *
     * @param string $address
     *
     * @return bool
     */
    public function isSuppressed( string $address )
    {
        $model = $this->addressModel();

        $record = $model->newQuery()
            ->where('address', $model::normalize($address))
            ->first();

        return $record !== null && $record->isSuppressed();
    }

    /**
     * Manually suppress an address — for an unsubscribe, an abuse report, or
     * any reason of your own. Creates the record if it does not exist yet.
     *
     * The activity entry written by EmailAddress::suppress() carries the
     * causer / source attribution, so a consumer can answer "who suppressed
     * this address, when, and why" from the ledger alone.
     *
     * @param string                                   $address
     * @param string                                   $reason
     *  Why the address is being suppressed — defaults to REASON_MANUAL.
     * @param \Illuminate\Database\Eloquent\Model|null $causer
     *  The acting user, when there is one. Stored via the morph map.
     * @param string|null                              $source
     *  Free-form label for the actor (e.g. 'dashboard', 'console'); also
     *  used as a fallback when the morph relation can't be hydrated across
     *  DB connections.
     *
     * @return EmailAddress
     */
    public function suppress(
        string $address,
        string $reason = EmailAddress::REASON_MANUAL,
        ?Model $causer = null,
        ?string $source = null,
    ) {
        $model = $this->addressModel();

        $record = $model->newQuery()->firstOrNew([
            'address' => $model::normalize($address),
        ]);

        $record->suppress($reason, $causer, $source);

        return $record;
    }

    /**
     * Lift the suppression on an address. Lifts the local row AND asks
     * every provider this address is associated with (per the local row's
     * `providers` list) to clear it from their list too — what happens
     * locally has to happen upstream, or the next sync would just put it
     * back.
     *
     * The activity entry written by EmailAddress::unsuppress() carries the
     * causer / source attribution plus the "Cleared at: ..." response blurb
     * for any providers whose API was called.
     *
     * @param string                                   $address
     * @param \Illuminate\Database\Eloquent\Model|null $causer
     *  The acting user, when there is one. Stored via the morph map.
     * @param string|null                              $source
     *  Free-form label for the actor (e.g. 'dashboard', 'console').
     * @param string|null                              $reason
     *  A human note for the ledger ("customer confirmed the mailbox was
     *  fixed"). Distinct from the address-row reason, which is cleared.
     *
     * @return array{address: EmailAddress, cleared: array<int, string>, manual: array<int, string>}
     *
     * Keys:
     *   - 'address'  the EmailAddress row, in its post-unsuppress (active) state.
     *   - 'cleared'  provider names whose sync was called successfully via API.
     *   - 'manual'   provider names whose sync wasn't available (SDK missing
     *                or no API key) or threw — operator has to clear those
     *                in the provider's dashboard manually.
     */
    public function unsuppress(
        string $address,
        ?Model $causer = null,
        ?string $source = null,
        ?string $reason = null,
    ) {
        $model      = $this->addressModel();
        $normalized = $model::normalize($address);

        $record = $model->newQuery()->firstOrNew(['address' => $normalized]);

        $cleared = [];
        $manual  = [];

        foreach ($record->providers ?? [] as $provider) {
            $sync = $this->sync($provider);

            if ($sync === null || ! $sync->isAvailable()) {
                $manual[] = $provider;
                continue;
            }

            try {
                $sync->unsuppress($normalized);
                $cleared[] = $provider;
            } catch (Throwable $e) {
                logger()->warning(
                    "Postmaster: provider unsuppress failed for {$provider}; the local row will still be lifted.",
                    ['address' => $address, 'error' => $e->getMessage()]
                );
                $manual[] = $provider;
            }
        }

        $record->unsuppress($causer, $source, $reason, [
            'response' => empty($cleared) ? null : 'Cleared at: '.implode(', ', $cleared),
        ]);

        return [
            'address' => $record,
            'cleared' => $cleared,
            'manual'  => $manual,
        ];
    }

    /**
     * Resolve the suppression sync for the given provider — or null if the
     * provider isn't registered, has no sync class configured, or the
     * configured class isn't loadable.
     *
     * @param string $provider
     *
     * @return \STS\Postmaster\Contracts\SuppressionSync|null
     */
    public function sync( $provider )
    {
        // The providers JSON column on email_addresses stores canonical-case
        // names ("Postmark", "SendGrid", …); the config keys are lower-case
        // identifiers ("postmark", "sendgrid", …). Accept either by
        // normalizing the lookup.
        $key    = strtolower((string) $provider);
        $config = $this->config['providers'][$key] ?? null;
        $class  = $config['sync'] ?? null;

        if (! is_string($class) || ! class_exists($class)) {
            return null;
        }

        return new $class($config);
    }

    /**
     * A fresh instance of the configured (swappable) email address model.
     *
     * @return EmailAddress
     */
    protected function addressModel()
    {
        $class = config('postmaster.persistence.address_model', EmailAddress::class);

        return new $class;
    }

    /**
     * Register the gate that decides who may view the dashboard. The callback
     * receives the request and must return true to allow access.
     *
     * @param Closure $callback
     *
     * @return $this
     */
    public function auth( Closure $callback )
    {
        $this->authCallback = $callback;

        return $this;
    }

    /**
     * Whether the given request may access the dashboard. With no gate
     * registered, access is allowed only in the local environment — so the
     * dashboard is never unguarded in production by accident.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return bool
     */
    public function authorize( $request )
    {
        return $this->authCallback === null
            ? app()->environment('local')
            : (bool) call_user_func($this->authCallback, $request);
    }
}

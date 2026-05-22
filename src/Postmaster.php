<?php

namespace STS\Postmaster;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use STS\Postmaster\Http\Controllers\WebhookController;
use STS\Postmaster\Http\Middleware\VerifyWebhook;
use STS\Postmaster\Models\EmailAddress;
use STS\Postmaster\Support\OutboundMetadata;
use Symfony\Component\Mime\Email;

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
     * Build a callback that tags a message with free-form labels.
     *
     * @param array<int, string> $tags
     *
     * @return Closure
     */
    public function tags( array $tags )
    {
        return function (Email $message) use ($tags) {
            $message->getHeaders()->addTextHeader(
                OutboundMetadata::HEADER_TAGS, (string) json_encode(array_values($tags))
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
     * @param string $address
     * @param string $reason
     *
     * @return EmailAddress
     */
    public function suppress( string $address, string $reason = EmailAddress::REASON_MANUAL )
    {
        $model = $this->addressModel();

        $record = $model->newQuery()->firstOrNew([
            'address' => $model::normalize($address),
        ]);

        return $record->suppress($reason);
    }

    /**
     * Lift the suppression on an address, returning it to active.
     *
     * @param string $address
     *
     * @return EmailAddress
     */
    public function unsuppress( string $address )
    {
        $model = $this->addressModel();

        $record = $model->newQuery()->firstOrNew([
            'address' => $model::normalize($address),
        ]);

        return $record->unsuppress();
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

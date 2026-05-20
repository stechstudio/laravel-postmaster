<?php

namespace STS\Postmaster;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use STS\Postmaster\Http\Controllers\WebhookController;
use STS\Postmaster\Http\Middleware\VerifyWebhook;
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
     * Build a callback that associates a message with the given model.
     *
     * Pass it to any message exposing withSymfonyMessage() — a Mailable or a
     * notification's MailMessage. The TracksEmailEvents trait's relatedTo()
     * uses this under the hood.
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
     * Register the webhook route. Call this from the consuming app's route
     * file. Authorization runs as middleware ahead of the controller.
     */
    public function routes()
    {
        Route::post($this->config['url'] . '/{provider}', WebhookController::class)
            ->middleware(VerifyWebhook::class)
            ->name('webhook.postmaster');
    }
}

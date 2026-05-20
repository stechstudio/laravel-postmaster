<?php

namespace STS\EmailEvents;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use STS\EmailEvents\Http\Controllers\WebhookController;
use STS\EmailEvents\Http\Middleware\VerifyWebhook;

class EmailEvents
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
     * EmailEvents constructor.
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
     * Register the webhook route. Call this from the consuming app's route
     * file. Authorization runs as middleware ahead of the controller.
     */
    public function routes()
    {
        Route::post($this->config['url'] . '/{provider}', WebhookController::class)
            ->middleware(VerifyWebhook::class)
            ->name('webhook.email-events');
    }
}

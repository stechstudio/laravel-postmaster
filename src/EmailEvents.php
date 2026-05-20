<?php

namespace STS\EmailEvents;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

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
     *
     */
    public function routes()
    {
        Route::post($this->config['url'] . '/{provider}', function ( Request $request, $provider ) {
            $this->provider($provider)
                ->authorize($request)
                ->adapt($request->all())
                ->dispatch();
        })->name('webhook.email-events');
    }
}

<?php

namespace STS\EmailEvents;

use Closure;
use Illuminate\Support\Arr;
use InvalidArgumentException;

/**
 * Resolves configured email providers by name.
 *
 * Each provider is a small composite (adapter class + authorizer + config),
 * always addressed explicitly by the {provider} route segment — there is no
 * "default" provider, so this is a purpose-built registry rather than an
 * extension of Laravel's driver Manager. Custom providers can be registered
 * at runtime with extend().
 */
class ProviderRegistry
{
    /** @var array */
    protected $config;

    /** @var array<string,Closure> */
    protected $customResolvers = [];

    /** @var array<string,Provider> */
    protected $resolved = [];

    public function __construct( array $config )
    {
        $this->config = $config;
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
        $this->customResolvers[$name] = $resolver;

        unset($this->resolved[$name]);

        return $this;
    }

    /**
     * All registered provider names — configured and custom.
     *
     * @return string[]
     */
    public function names()
    {
        return array_values(array_unique(array_merge(
            array_keys(Arr::get($this->config, 'providers', [])),
            array_keys($this->customResolvers)
        )));
    }

    /**
     * @param string $name
     *
     * @return bool
     */
    public function has( $name )
    {
        return in_array($name, $this->names(), true);
    }

    /**
     * Resolve (and cache) the Provider for the given name.
     *
     * @param string $name
     *
     * @return Provider
     */
    public function get( $name )
    {
        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }

        return $this->resolved[$name] = $this->resolve($name);
    }

    /**
     * @param string $name
     *
     * @return Provider
     */
    protected function resolve( $name )
    {
        if (isset($this->customResolvers[$name])) {
            return ($this->customResolvers[$name])($this->config);
        }

        $definition = Arr::get($this->config, "providers.$name");

        if ($definition === null) {
            throw new InvalidArgumentException("Email event provider [$name] is not configured.");
        }

        return new Provider(
            $name,
            Arr::get($definition, 'adapter'),
            $this->resolveAuthorizer(Arr::get($definition, 'auth')),
            Arr::get($this->config, 'on_invalid', 'log')
        );
    }

    /**
     * Resolve a provider's authorizer. The "auth" value may be a callable, a
     * fully-qualified authorizer class, or the name of a registered authorizer.
     *
     * @param callable|string $auth
     *
     * @return callable
     */
    protected function resolveAuthorizer( $auth )
    {
        if (is_callable($auth)) {
            return $auth;
        }

        if (is_string($auth) && class_exists($auth)) {
            return app($auth);
        }

        $class = Arr::get($this->config, "authorizers.$auth");

        if ($class === null) {
            throw new InvalidArgumentException("Unknown email event authorizer [$auth].");
        }

        return app($class);
    }
}

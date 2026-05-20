<?php

namespace STS\EmailEvents;

use Illuminate\Support\ServiceProvider;
use STS\EmailEvents\Auth\BasicHttpAuth;
use STS\EmailEvents\Auth\TokenAuth;
use STS\EmailEvents\Providers\Mailgun\SignatureAuth;

class EmailEventsServiceProvider extends ServiceProvider
{
    /**
     * Perform post-registration booting of services.
     *
     * @return void
     */
    public function boot()
    {
        // Publishing is only necessary when using the CLI.
        if ($this->app->runningInConsole()) {
            $this->bootForConsole();
        }

        // For local dev let's debug log all email events
        if($this->app->environment(['local', 'development'])) {
            $this->app['events']->listen(EmailEvent::class, function(EmailEvent $event) {
                logger("Received email event", $event->toArray());
            });
        }
    }

    /**
     * Register any package services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/email-events.php', 'email-events');

        // Register the service the package provides.
        $this->app->singleton('emailevents', function ($app) {
            return new EmailEvents(
                $app['config']->get('email-events')
            );
        });

        $this->app->bind(TokenAuth::class, function($app) {
            return new TokenAuth(
                $app['config']->get('email-events.token'),
                $app['config']->get('email-events.token_parameter')
            );
        });

        $this->app->bind(BasicHttpAuth::class, function($app) {
            return new BasicHttpAuth(
                $app['config']->get('email-events.basic_username'),
                $app['config']->get('email-events.basic_password')
            );
        });

        $this->app->bind(SignatureAuth::class, function($app) {
            return new SignatureAuth(
                $app['config']->get('email-events.signature_key')
            );
        });
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return ['emailevents'];
    }
    
    /**
     * Console-specific booting.
     *
     * @return void
     */
    protected function bootForConsole()
    {
        // Publishing the configuration file.
        $this->publishes([
            __DIR__.'/../config/email-events.php' => config_path('email-events.php'),
        ], 'email-events.config');
    }
}

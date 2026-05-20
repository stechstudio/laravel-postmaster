<?php

namespace STS\EmailEvents;

use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\ServiceProvider;
use STS\EmailEvents\Auth\BasicHttpAuth;
use STS\EmailEvents\Auth\TokenAuth;
use STS\EmailEvents\Listeners\RecordOutboundMessage;
use STS\EmailEvents\Listeners\UpdateMessageFromEvent;
use STS\EmailEvents\Providers\Mailgun\SignatureAuth as MailgunSignatureAuth;
use STS\EmailEvents\Providers\Resend\SignatureAuth as ResendSignatureAuth;
use STS\EmailEvents\Providers\SendGrid\SignatureAuth as SendGridSignatureAuth;

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

        // Optional persistence: record outbound mail and update those records
        // as webhook events arrive.
        if ($this->app['config']->get('email-events.persistence.enabled')) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

            $this->app['events']->listen(MessageSent::class, RecordOutboundMessage::class);
            $this->app['events']->listen(EmailEvent::class, UpdateMessageFromEvent::class);
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

        $this->app->alias('emailevents', EmailEvents::class);

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

        $this->app->bind(MailgunSignatureAuth::class, function($app) {
            return new MailgunSignatureAuth(
                $app['config']->get('email-events.providers.mailgun.signing_key')
            );
        });

        $this->app->bind(SendGridSignatureAuth::class, function($app) {
            return new SendGridSignatureAuth(
                $app['config']->get('email-events.providers.sendgrid.verification_key')
            );
        });

        $this->app->bind(ResendSignatureAuth::class, function($app) {
            return new ResendSignatureAuth(
                $app['config']->get('email-events.providers.resend.signing_secret')
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

        // Publishing the persistence migration.
        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'email-events.migrations');
    }
}

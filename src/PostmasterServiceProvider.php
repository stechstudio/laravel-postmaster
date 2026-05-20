<?php

namespace STS\Postmaster;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\ServiceProvider;
use STS\Postmaster\Auth\BasicHttpAuth;
use STS\Postmaster\Auth\TokenAuth;
use STS\Postmaster\Console\PruneEmailContent;
use STS\Postmaster\Console\PruneEmailMessageEvents;
use STS\Postmaster\Listeners\RecordOutboundMessage;
use STS\Postmaster\Listeners\StashOutboundMetadata;
use STS\Postmaster\Listeners\UpdateMessageFromEvent;
use STS\Postmaster\Providers\Mailgun\SignatureAuth as MailgunSignatureAuth;
use STS\Postmaster\Providers\Resend\SignatureAuth as ResendSignatureAuth;
use STS\Postmaster\Providers\SendGrid\SignatureAuth as SendGridSignatureAuth;

class PostmasterServiceProvider extends ServiceProvider
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

        // Register the webhook route automatically. Skipped when routes are
        // cached (the cache already holds it) or disabled via config.
        if ($this->app['config']->get('postmaster.register_route') && ! $this->app->routesAreCached()) {
            $this->app->make(Postmaster::class)->routes();
        }

        // For local dev let's debug log all email events
        if($this->app->environment(['local', 'development'])) {
            $this->app['events']->listen(EmailEvent::class, function(EmailEvent $event) {
                logger("Received email event", $event->toArray());
            });
        }

        // Optional persistence: record outbound mail and update those records
        // as webhook events arrive.
        if ($this->app['config']->get('postmaster.persistence.enabled')) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

            $this->app['events']->listen(MessageSending::class, StashOutboundMetadata::class);
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
        $this->mergeConfigFrom(__DIR__.'/../config/postmaster.php', 'postmaster');

        // Register the service the package provides.
        $this->app->singleton('postmaster', function ($app) {
            return new Postmaster(
                $app['config']->get('postmaster')
            );
        });

        $this->app->alias('postmaster', Postmaster::class);

        $this->app->bind(TokenAuth::class, function($app) {
            return new TokenAuth(
                $app['config']->get('postmaster.token'),
                $app['config']->get('postmaster.token_parameter')
            );
        });

        $this->app->bind(BasicHttpAuth::class, function($app) {
            return new BasicHttpAuth(
                $app['config']->get('postmaster.basic_username'),
                $app['config']->get('postmaster.basic_password')
            );
        });

        $this->app->bind(MailgunSignatureAuth::class, function($app) {
            return new MailgunSignatureAuth(
                $app['config']->get('postmaster.providers.mailgun.signing_key')
            );
        });

        $this->app->bind(SendGridSignatureAuth::class, function($app) {
            return new SendGridSignatureAuth(
                $app['config']->get('postmaster.providers.sendgrid.verification_key')
            );
        });

        $this->app->bind(ResendSignatureAuth::class, function($app) {
            return new ResendSignatureAuth(
                $app['config']->get('postmaster.providers.resend.signing_secret')
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
        return ['postmaster'];
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
            __DIR__.'/../config/postmaster.php' => config_path('postmaster.php'),
        ], 'postmaster.config');

        // Publishing the persistence migration.
        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'postmaster.migrations');

        if ($this->app['config']->get('postmaster.persistence.enabled')) {
            $this->commands([PruneEmailContent::class, PruneEmailMessageEvents::class]);

            // Auto-schedule content pruning when a retention window is set.
            if ($this->app['config']->get('postmaster.persistence.prune_content_after_days') !== null) {
                $this->app->booted(function () {
                    $this->app->make(Schedule::class)
                        ->command('postmaster:prune-content')
                        ->daily();
                });
            }

            // Auto-schedule timeline pruning when a retention window is set.
            if ($this->app['config']->get('postmaster.persistence.prune_events_after_days') !== null) {
                $this->app->booted(function () {
                    $this->app->make(Schedule::class)
                        ->command('postmaster:prune-events')
                        ->daily();
                });
            }
        }
    }
}

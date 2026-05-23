<?php

namespace STS\Postmaster;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use STS\Postmaster\Auth\BasicHttpAuth;
use STS\Postmaster\Auth\TokenAuth;
use STS\Postmaster\Console\PruneEmailContent;
use STS\Postmaster\Console\PruneEmailMessageEvents;
use STS\Postmaster\Console\VerifySetup;
use STS\Postmaster\Http\Middleware\AuthorizeDashboard;
use STS\Postmaster\Listeners\InterceptSandboxMail;
use STS\Postmaster\Listeners\InterceptSuppressedRecipient;
use STS\Postmaster\Listeners\RecordOutboundMessage;
use STS\Postmaster\Listeners\RelayVerificationEvent;
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

        // The dashboard's views are always registered (cheap, and harmless
        // when the dashboard is off); only its routes are gated.
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'postmaster');

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

        // Relay webhook events to a running postmaster:verify command, which
        // watches from a separate process.
        $this->app['events']->listen(EmailEvent::class, RelayVerificationEvent::class);

        // Optional persistence: record outbound mail and update those records
        // as webhook events arrive.
        if ($this->app['config']->get('postmaster.persistence.enabled')) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

            $this->app['events']->listen(MessageSending::class, StashOutboundMetadata::class);
            $this->app['events']->listen(MessageSent::class, RecordOutboundMessage::class);
            $this->app['events']->listen(EmailEvent::class, UpdateMessageFromEvent::class);

            // The optional superadmin dashboard reads the persistence tables,
            // so it is only available alongside persistence.
            if ($this->app['config']->get('postmaster.dashboard.enabled')) {
                $this->registerDashboard();
            }
        }

        // Block-suppressed delivery: refuse to send to suppression-listed
        // addresses. Registered before InterceptSandboxMail so a deliberate
        // block beats a generic sandbox intercept.
        if ($this->app['config']->get('postmaster.block_suppressed')) {
            $this->app['events']->listen(MessageSending::class, InterceptSuppressedRecipient::class);
        }

        // Sandbox delivery: intercept and suppress all outbound mail. Listed
        // after StashOutboundMetadata so relatedTo()/forTenant() metadata is
        // stashed before InterceptSandboxMail records the message.
        if ($this->app['config']->get('postmaster.delivery') === 'sandbox') {
            $this->app['events']->listen(MessageSending::class, InterceptSandboxMail::class);

            // Sandbox silently drops every email — almost never what you want
            // in production. Surface it loudly rather than refusing to boot.
            if ($this->app->environment('production')) {
                logger()->warning(
                    'Postmaster sandbox delivery is enabled in production: all outbound '
                    .'email is being intercepted and suppressed. Set POSTMASTER_DELIVERY=normal '
                    .'unless this is intentional.'
                );
            }
        }
    }

    /**
     * Register the dashboard's gated route group.
     *
     * @return void
     */
    protected function registerDashboard()
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        Route::group([
            'prefix'     => $this->app['config']->get('postmaster.dashboard.path', 'postmaster'),
            'middleware' => array_merge(
                (array) $this->app['config']->get('postmaster.dashboard.middleware', ['web']),
                [AuthorizeDashboard::class]
            ),
        ], function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/dashboard.php');
        });
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

        // The setup check is useful regardless of persistence.
        $this->commands([VerifySetup::class]);

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

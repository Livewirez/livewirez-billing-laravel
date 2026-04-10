<?php

namespace Livewirez\Billing;

use Illuminate\Support\Facades\Gate;
use Livewirez\Billing\OrdersManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Livewirez\Billing\BillingManager;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schedule;
use Livewirez\Billing\Commands\SaveCurrency;
use Livewirez\Billing\Actions\HandleWebhooks;
use Illuminate\Contracts\Foundation\Application;
use Livewirez\Billing\Events\SubscriptionRenewed;
use Livewirez\Billing\Policies\BillingOrderPolicy;
use Livewirez\Billing\Events\SubscriptionActivated;
use Livewirez\Billing\Policies\BillingSubscriptionPolicy;
use Livewirez\Billing\Listeners\HandleSubscriptionRenewed;
use Livewirez\Billing\Listeners\HandleSubscriptionActivated;
use Livewirez\Billing\Http\Middleware\PolarWebhookMiddleware;
use Livewirez\Billing\Http\Middleware\PaddleWebhookMiddleware;
use Livewirez\Billing\Http\Middleware\PayPalWebhookMiddleware;
use Livewirez\Billing\Http\Controllers\HandleWebhooksController;

class BillingServiceProvider extends ServiceProvider 
{
    // fired after everything in the application including 3rd party libraries have been loaded up
    // bootstrap web services
    // listen for events
    // publish configuration files or database migrations
    public function boot()
    {
        $this->configureConfig();
        $this->configureMigrations();
        $this->configureEvents();
        $this->configurePolicies();
        $this->configureRoutes();
        $this->configureCommands();
        $this->configureScheduledCommands();
    }

    // great for extending functionality to your current service provider class before the application is ready
    // through singletons or other service providers
    // extend functionality from other classes
    // register service providers
    // create singleton classes
    public function register()
    {
        $this->configureManagers();
        
    }

    public function configureScheduledCommands()
    {
        Schedule::command(SaveCurrency::class)->mondays();
    }

    protected function configureManagers() 
    {
        $this->app->singleton(BillingManager::class, function (Application $app) {
            return new BillingManager($app);
        });
    }

    protected function configureConfig()
    {
        // php artisan vendor:publish
        
        $this->publishes([
            __DIR__.'/../config/billing.php' => config_path('billing.php'),
        ], 'billing-laravel-config');
       
    }
    
    public function configureCommands()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                SaveCurrency::class,
            ]);
        }
    }

    protected function configureEvents()
    {
        Event::listen(
            SubscriptionActivated::class,
            HandleSubscriptionActivated::class,
        );

        Event::listen(
            SubscriptionRenewed::class,
            HandleSubscriptionRenewed::class,
        );
    }

    protected function configureMigrations()
    {
        // php artisan vendor:publish

        if (app()->runningInConsole()) {

            //$this->loadMigrationsFrom(__DIR__.'/../database/migrations');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'billing-laravel-migrations');
        }
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [
            BillingManager::class
        ];
    }

    protected function configurePolicies()
    {
        Gate::policy(Billing::$billingOrder, BillingOrderPolicy::class);
        Gate::policy(Billing::$billingSubscription, BillingSubscriptionPolicy::class);
    }

    protected function configureRoutes()
    {
        Route::prefix('webhooks/payments')->name('webhooks.')->group(function () {
    
            Route::post('polar', [HandleWebhooksController::class, 'handlePolarWebhooks'])
                ->middleware([PolarWebhookMiddleware::class]) 
                ->name('polar.handle');

            Route::post('paypal', [HandleWebhooksController::class, 'handlePayPalWebhooks'])
                ->middleware([PayPalWebhookMiddleware::class]) 
                ->name('paypal.handle');

            Route::post('paddle', [HandleWebhooksController::class, 'handlePaddleWebhooks'])
                ->middleware([PaddleWebhookMiddleware::class]) 
                ->name('paddle.handle');
        });
    }
}
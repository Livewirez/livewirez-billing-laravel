<?php

namespace Livewirez\Billing;

use Illuminate\Support\Facades\Crypt;
use RuntimeException;
use Livewirez\Billing\Enums\PaymentProvider;
use Livewirez\Billing\Providers\PolarProvider;
use Illuminate\Support\MultipleInstanceManager;
use Livewirez\Billing\Providers\PayPalProvider;
use Livewirez\Billing\Providers\PayPalHttpProvider;
use Livewirez\Billing\Interfaces\PaymentProviderInterface;
use Livewirez\Billing\Interfaces\TokenizedPaymentProviderInterface;
use Livewirez\Billing\Providers\PaypalTokenProvider;

// PaymentProvider::PayPal->value => new PayPalProvider(),
class BillingManager extends MultipleInstanceManager
{
    protected $driverKey = 'provider';

    /**
     * Get a driver instance by name.
     *
     * @param  string|null  $name
     * @return mixed
     */
    public function driver($name = null)
    {
        return $this->instance($name);
    }

    /**
     * Get a provider instance by name.
     *
     * @param  string|null  $name
     * @return PaymentProviderInterface
     */
    public function provider($name = null): PaymentProviderInterface
    {
        return $this->instance($name);
    }

    /**
     * Create an instance of the process payment provider.
     *
     * @param  array  $config
     * @return PayPalHttpProvider|PayPalProvider
     */
    public function createPaypalProvider(array $config)
    {
        return new PayPalHttpProvider($config);
    }

    public function createPolarProvider(array $config)
    {
        return new PolarProvider($config);
    }

    /**
     * Get the default instance name.
     *
     * @return string
     */
    public function getDefaultInstance()
    {
        return $this->app['config']['billing.default']
            ?? $this->app['config']['billing.provider']
            ?? 'polar';
    }

    /**
     * Set the default instance name.
     *
     * @param  string  $name
     * @return void
     */
    public function setDefaultInstance($name)
    {
        $this->app['config']['billing.default'] = $name;
        $this->app['config']['billing.provider'] = $name;
    }

    /**
     * Get the instance specific configuration.
     *
     * @param  string  $name
     * @return array
     */
    public function getInstanceConfig($name)
    {
        return $this->app['config']->get(
            'billing.providers.'.$name, ['provider' => $name],
        );
    }
}
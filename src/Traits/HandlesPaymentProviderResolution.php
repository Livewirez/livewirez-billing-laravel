<?php

namespace Livewirez\Billing\Traits;

use Livewirez\Billing\Facades\Billing;
use Livewirez\Billing\Enums\PaymentProvider;
use Livewirez\Billing\Interfaces\PaymentProviderInterface;

trait HandlesPaymentProviderResolution
{
    public function resolveProviderValue(PaymentProvider|string $paymentProvider): string
    {
        return is_string($paymentProvider) ? $paymentProvider : $paymentProvider->value;
    } 

    public function provider(string $provider): PaymentProviderInterface 
    {
        return Billing::provider($provider);
    }
}
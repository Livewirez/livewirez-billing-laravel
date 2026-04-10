<?php 

namespace Livewirez\Billing\Interfaces;

use Livewirez\Billing\Enums\PaymentProvider;


interface ResolvesPaymentProvider
{
    public function resolveProviderValue(PaymentProvider|string $paymentProvider): string;

    public function provider(string $provider): PaymentProviderInterface;
}

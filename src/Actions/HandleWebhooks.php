<?php 

namespace Livewirez\Billing\Actions;

use Illuminate\Http\Request;
use Livewirez\Billing\PaymentResult;
use Livewirez\Billing\OrdersManager;
use Livewirez\Billing\Interfaces\Billable;
use Livewirez\Billing\Enums\PaymentProvider;
use Symfony\Component\HttpFoundation\Response;

class HandleWebhooks
{
    public function __construct(protected OrdersManager $ordersManager) {}


    public function handle(
       Request $request, PaymentProvider|string $provider,
    ): Response
    {
        return $this->ordersManager->provider(is_string($provider) ? $provider : $provider->value)->handleWebhook($request);
    }
}
<?php 

namespace Livewirez\Billing\Http\Controllers;

use Illuminate\Http\Request;
use Livewirez\Billing\Enums\PaymentProvider;
use Livewirez\Billing\Actions\HandleWebhooks;
use Symfony\Component\HttpFoundation\Response;

class HandleWebhooksController
{
    public function handlePolarWebhooks(Request $request, HandleWebhooks $action): Response
    {
        return $action->handle($request, PaymentProvider::Polar);
    }

    public function handlePayPalWebhooks(Request $request, HandleWebhooks $action): Response
    {
        return $action->handle($request, PaymentProvider::PayPal);
    }

    public function handlePaddleWebhooks(Request $request, HandleWebhooks $action): Response
    {
        return $action->handle($request, PaymentProvider::Paddle);
    }

}
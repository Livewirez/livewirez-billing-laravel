<?php

namespace Livewirez\Billing\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PayPalWebhookMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(! $this->validateRequest($request), 400);

        return $next($request);
    }

    private function validateRequest(Request $request): bool 
    {
        return hash_equals(
            hash_hmac(
                'sha256', 
                config('billing.providers.paypal.paypal_webhook_secret_value'),
                sha1(config('billing.providers.paypal.paypal_webhook_secret_key'))
            ), 
            (string) $request->query('signature')
        );
    }
}
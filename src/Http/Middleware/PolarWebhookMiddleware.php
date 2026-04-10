<?php

namespace Livewirez\Billing\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class PolarWebhookMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
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
                config('billing.providers.polar.webhook_secret_value'),
                sha1(config('billing.providers.polar.webhook_secret_key'))
            ), 
            (string) $request->query('signature')
        );
    }

    private function verifySignature(Request $request): bool 
    {
        $signingSecret = base64_encode(config('billing.providers.polar.webhook_secret'));

        if (! $webhookId = $request->header('webhook-id')) {
            Log::error('Polar Webhook Id Not in Request Header');
            return false;
        }

        if (! $webhookSignature = $request->header('webhook-signature')) {
            Log::error('Polar Webhook Signature Not in Request Header');
            return false;
        }

        if (! $webhookTimestamp = $request->header('webhook-timestamp')) {
            Log::error('Polar Webhook Timestamp Not in Request Header');
            return false;
        }

        $content = $request->getContent();
    
    
        return false;
    }
}
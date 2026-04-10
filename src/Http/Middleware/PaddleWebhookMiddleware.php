<?php

namespace Livewirez\Billing\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

use function Livewirez\Billing\exception_info;

class PaddleWebhookMiddleware
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
                config('billing.providers.paddle.webhook_secret_value'),
                sha1(config('billing.providers.paddle.webhook_secret_key'))
            ), 
            (string) $request->query('signature')
        );// && $this->validateBodySignature($request);
    }


    /** @see https://developer.paddle.com/webhooks/signature-verification#examples-verify-manually */
    private function validateBodySignature(Request $request): bool
    {
        try {

            // 1. Get Paddle-Signature header
            $paddleSignature = $request->header('paddle-signature');
    
            if (! $paddleSignature) return false;
    
            $webhookSecret = config('billing.providers.paddle.webhook_secret');
    
            if (! $webhookSecret) throw new \Exception('Server misconfigured');
    
            // 2. Extract timestamp and signature from header
            if (!str_contains($paddleSignature, ";")) {
                Log::error('Invalid Paddle-Signature format');
    
                return false;
            }
    
            $parts = explode(";", $paddleSignature);
    
            if (count($parts) !== 2) {
                Log::error("Invalid Paddle-Signature format");
    
                return false;
            }
    
            [$timestampPart, $signaturePart] = array_map(
                fn (string $part) => explode("=", $part)[1] ?? null,
                $parts
            );
    
            if (!$timestampPart || !$signaturePart) {
                Log::error("Unable to extract timestamp or signature from Paddle-Signature header");
    
                return false;
            }
    
    
            $timestamp = (int) $timestampPart;
            $signature = $signaturePart;
    
            // 3. Optional: Reject if older than 5 seconds
            if (abs(time() - $timestamp) > 5) {
                Log::warning("Webhook event expired: timestamp={$timestamp}, now=" . time());
                return false;
            }
    
            // 4. Build signed payload
            $bodyRaw = $request->getContent(); // raw request body
            $signedPayload = "{$timestamp}:{$bodyRaw}";
    
            // 5. Hash payload with HMAC SHA256
            $computedSignature = hash_hmac('sha256', $signedPayload, $webhookSecret);
    
            // 6. Timing-safe comparison
            if (!hash_equals($computedSignature, $signature)) {
                Log::error("Signature mismatch. Expected={$computedSignature}, Received={$signature}");
                return false;
            }
    
            return true;
        } catch (\Throwable $th) {
            exception_info($th, [__METHOD__ . __LINE__], ['file']);

            return false;
        }
    }

}
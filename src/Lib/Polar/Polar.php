<?php 

namespace Livewirez\Billing\Lib\Polar;

use Exception;
use Illuminate\Http\Client\Batch;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\ConnectionException;
use Livewirez\Billing\Lib\Polar\Exceptions\PolarApiError;
use Livewirez\Billing\Lib\Polar\Data\Products\ListProductsData;
use Livewirez\Billing\Lib\Polar\Data\Checkout\CheckoutSessionData;
use Livewirez\Billing\Lib\Polar\Data\Sessions\CustomerSessionData;
use Livewirez\Billing\Lib\Polar\Data\Subscriptions\SubscriptionData;
use Livewirez\Billing\Lib\Polar\Data\Products\ListProductsRequestData;
use Livewirez\Billing\Lib\Polar\Data\Checkout\CreateCheckoutSessionData;
use Livewirez\Billing\Lib\Polar\Data\Subscriptions\SubscriptionCancelData;
use Livewirez\Billing\Lib\Polar\Data\Subscriptions\SubscriptionUpdateProductData;
use Livewirez\Billing\Lib\Polar\Data\Sessions\CustomerSessionCustomerIDCreateData;
use Livewirez\Billing\Lib\Polar\Data\Sessions\CustomerSessionCustomerExternalIDCreateData;

class Polar
{
    /**
     * Create a checkout session.
     *
     * @throws PolarApiError
     */
    public static function createCheckoutSession(CreateCheckoutSessionData $request): ?CheckoutSessionData
    {
        try {
            $response = self::api("POST", "v1/checkouts", $request->toArray());
            \Illuminate\Support\Facades\Log::info('Polar Checkout Session Created', [
                'response' => $response->json(),
                'request' => $request->toArray()
            ]);

            return CheckoutSessionData::from($response->json());
        } catch (PolarApiError $e) {
            throw new PolarApiError($e->getMessage(), 400);
        }
    }

    /**
     * Update a subscription.
     *
     * @throws PolarApiError
     */
    public static function updateSubscription(string $subscriptionId, SubscriptionUpdateProductData|SubscriptionCancelData $request): SubscriptionData
    {
        try {
            $response = self::api("POST", "v1/subscriptions/$subscriptionId", $request->toArray());

            return SubscriptionData::from($response->json());
        } catch (PolarApiError $e) {
            throw new PolarApiError($e->getMessage(), 400);
        }
    }

    /**
     * List all products.
     *
     * @throws PolarApiError
     */
    public static function listProducts(?ListProductsRequestData $request): ListProductsData
    {
        try {
            $response = self::api("GET", "v1/products", $request->toArray());

            return ListProductsData::from($response->json());
        } catch (PolarApiError $e) {
            throw new PolarApiError($e->getMessage(), 400);
        }
    }

    /**
     * Create a customer session.
     *
     * @throws PolarApiError
     */
    public static function createCustomerSession(CustomerSessionCustomerIDCreateData|CustomerSessionCustomerExternalIDCreateData $request): CustomerSessionData
    {
        try {
            $response = self::api("POST", "v1/customer-sessions", $request->toArray());

            return CustomerSessionData::from($response->json());
        } catch (PolarApiError $e) {
            throw new PolarApiError($e->getMessage(), 400);
        }
    }

    /**
     * Perform a Polar API call.
     *
     * @param array<string, mixed> $payload The payload to send to the API.
     *
     * @throws Exception
     * @throws Exception
     */
    public static function api(string $method, string $uri, array $payload = [], array $headers = []): Response
    {
        if (empty($apiKey = config('billing.providers.polar.api_key'))) {
            throw new Exception('Polar API key not set.');
        }

        $payload = collect($payload)
            ->filter(fn($value) => $value !== '' && $value !== [])
            ->toArray();

        \Illuminate\Support\Facades\Log::debug('Polar API Request', [
            'method' => $method,
            'uri' => $uri,
            'payload' => $payload,
        ]);

        $environment = config('billing.providers.polar.environment');

        $api = config(
            "billing.providers.polar.base_url.{$environment}",
            app()->environment('production') ? 'https://api.polar.sh' : 'https://sandbox-api.polar.sh'
        );

        $pendingRequest = Http::withToken($apiKey)
            ->asJson()
            ->withHeaders($headers)
            ->retry(3, 5, fn(Exception $exception, PendingRequest $request) => $exception instanceof ConnectionException)
            ->throw(function (Response $r, RequestException $e) use ($uri) {
                \Illuminate\Support\Facades\Log::info("Polar API Error {$uri}", [
                    'response' => $r,
                    'json_response' => $r->json(),
                    'error' => $e->getMessage(),
                    'status' => $r->status()
                ]);
            })
            ->truncateExceptionsAt(1500);
            
        $response = $pendingRequest->$method("$api/$uri", $payload);

        if ($response->failed()) {
            throw new PolarApiError(json_encode($response['detail']), 422);
        }

        return $response;
    }

    public static function apiPool(callable $callback): array
    {
        if (empty($apiKey = config('billing.providers.polar.api_key'))) {
            throw new Exception('Polar API key not set.');
        }

        $environment = config('billing.providers.polar.environment');

        $api = config(
            "billing.providers.polar.base_url.{$environment}",
            app()->environment('production') ? 'https://api.polar.sh' : 'https://sandbox-api.polar.sh'
        );

        $responses = Http::pool(
            fn (Pool $pool) => $callback($pool, $api, $apiKey)
        );

        return $responses;
    }

    public static function apiBatch(
        callable $callback,
        ?callable $before = null,
        ?callable $progress = null,
        ?callable $catch = null,
        ?callable $then = null,
        ?callable $finally  = null
    ): array
    {
        if (empty($apiKey = config('billing.providers.polar.api_key'))) {
            throw new Exception('Polar API key not set.');
        }

        $environment = config('billing.providers.polar.environment');

        $api = config(
            "billing.providers.polar.base_url.{$environment}",
            app()->environment('production') ? 'https://api.polar.sh' : 'https://sandbox-api.polar.sh'
        );

        $request = Http::batch(
            fn (Batch $batch) => $callback($batch, $api, $apiKey)
        );

        if ($before) {
            $request->before(fn (Batch $batch) => $before($batch));
        }

        if ($progress) {
            $request->progress(
                fn (Batch $batch, int|string $key, Response $response) => $progress($batch, $key, $response)
            );
        }

        if ($catch) {
            $request->catch(
                fn (
                    Batch $batch, 
                    int|string $key, 
                    Response|RequestException $response
                ) => $catch($batch, $key, $response)
            );
        }

        if ($then) {
            $request->then(fn (Batch $batch, array $results) => $then($batch, $results));
        }

        if ($finally) {
            $request->finally(fn (Batch $batch, array $results) => $finally($batch, $results));
        }

        return $request->send();
    }
}
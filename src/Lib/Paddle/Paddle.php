<?php 

namespace Livewirez\Billing\Lib\Paddle;

use Closure;
use Exception;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Batch;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\ConnectionException;
use Livewirez\Billing\Lib\Paddle\Exceptions\PaddleApiError;

class Paddle
{
     /**
     * Perform a Polar API call.
     *
     * @param array<string, mixed> $payload The payload to send to the API.
     *
     * @throws Exception
     */
    public static function api(string $method, string $uri, array $payload = [], array $headers = []): Response
    {
        if (empty($apiKey = config('billing.providers.paddle.api_key'))) {
            throw new Exception('Paddle API key not set.');
        }

        $payload = collect($payload)
            ->filter(fn($value) => $value !== '' && $value !== [])
            ->toArray();

        \Illuminate\Support\Facades\Log::debug('Polar API Request', [
            'method' => $method,
            'uri' => $uri,
            'payload' => $payload,
        ]);

        $environment = config('billing.providers.paddle.environment');

        $api = config(
            "billing.providers.paddle.base_url.{$environment}",
            app()->environment('production') ? 'https://sandbox-api.paddle.com' : 'https://api.paddle.com'
        );
        
        $pendingRequest = Http::withToken($apiKey)
            ->asJson()
            ->withHeaders($headers)
            ->retry(3, 5, fn(Exception $exception, PendingRequest $request) => $exception instanceof ConnectionException)
            ->throw(function (Response $r, RequestException $e) use ($uri) {
                \Illuminate\Support\Facades\Log::info("Paddle API Error {$uri}", [
                    'response' => $r,
                    'json_response' => $r->json(),
                    'error' => $e->getMessage(),
                    'status' => $r->status()
                ]);
            })
            ->truncateExceptionsAt(1500);
            
        $response = $pendingRequest->$method("$api/$uri", $payload);

        if ($response->failed()) {
            throw new PaddleApiError(json_encode($response['detail']), 422);
        }

        return $response;
    }

    public static function apiPool(callable $callback): array
    {
        if (empty($apiKey = config('billing.providers.paddle.api_key'))) {
            throw new Exception('Paddle API key not set.');
        }

        $environment = config('billing.providers.paddle.environment');

        $api = config(
            "billing.providers.paddle.base_url.{$environment}",
            app()->environment('production') ? 'https://sandbox-api.paddle.com' : 'https://api.paddle.com'
        );

        $responses = Http::pool(
            fn (Pool $pool) => $callback($pool, $api, $apiKey)
        );

        return $responses;
    }

    public static function apiBatch(
        callable $callback,
        /** @var (callable(Batch): void)|null  */
        ?callable $before = null,
        /** @var (callable(Batch, int|string, Response): void)|null  */
        ?callable $progress = null,
        /** @var (callable(Batch, int|string, Response|RequestException): void)|null  */
        ?callable $catch = null,
        /** @var (callable(Batch, array<int|string, Response> ): void) */
        ?callable $then = null,
        /** @var (callable(Batch, array<int|string, Response> ): void) */
        ?callable $finally  = null
    ): array
    {
       if (empty($apiKey = config('billing.providers.paddle.api_key'))) {
            throw new Exception('Paddle API key not set.');
        }

        $environment = config('billing.providers.paddle.environment');

        $api = config(
            "billing.providers.paddle.base_url.{$environment}",
            app()->environment('production') ? 'https://api.paddle.com' : 'https://sandbox-api.paddle.com'
        );

        $request = Http::batch(
            fn (Batch $batch) => $callback($batch, $api, $apiKey)
        );

        if ($before) {
            $request->before(Closure::fromCallable($before));
        }

        if ($progress) {
            $request->progress(Closure::fromCallable($progress));
        }

        if ($catch) {
            $request->catch(Closure::fromCallable($catch));
        }

        if ($then) {
            $request->then(Closure::fromCallable($then));
        }

        if ($finally) {
            $request->finally(Closure::fromCallable($finally));
        }

        return $request->send();
    }
}
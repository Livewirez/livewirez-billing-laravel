<?php

namespace Livewirez\Billing\Lib\PayPal;

use Exception;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Client\PendingRequest;
use Livewirez\Billing\Enums\RequestMethod;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\ConnectionException;

class PayPal
{
    protected static array $config = [];

    protected static function resolveConfig()
    {
        if (empty($clientId = config('billing.providers.paypal.client_id')) || empty($clientSecret = config('billing.providers.paypal.client_secret'))) {
            throw new Exception('Paypal Client auth not set.');
        }

        static::$config = config('billing.providers.paypal');
    }

    protected static function getAccessToken(): string
    { 
        static::resolveConfig();

        return Cache::remember('paypal_access_token', static::$config['expires_in'], function (): string {
            $response = Http::withBasicAuth(static::$config['client_id'], static::$config['client_secret'])
                ->asForm()
                ->retry(2, 100, fn (Exception $exception, PendingRequest $request) => $exception instanceof ConnectionException)
                ->throw()
                ->post(static::$config['base_url'][static::$config['environment']] . '/v1/oauth2/token', [
                    'grant_type' => 'client_credentials',
                ]);

            Cache::put('paypal_access_token', $response->json('access_token'), $response->json('expires_in'));
            
            return $response->json('access_token');
        });
    }

    public static function makeRequest(string $uri, array $data = [], array $headers = [], RequestMethod $method = RequestMethod::Post): Response
    {
        $token = static::getAccessToken();

        $client = Http::baseUrl(static::$config['base_url'][static::$config['environment']])
                ->asJson()
                ->withToken($token)
                ->withHeaders($headers)
                ->withHeader('prefer', 'return=representation') // 'return=representation'
                ->retry(3, 100, fn(Exception $exception, PendingRequest $request) => $exception instanceof ConnectionException)
                ->throw(function (Response $r, RequestException $e) use ($uri) {
                    \Illuminate\Support\Facades\Log::info(collect([
                        'response' => $r,
                        'json_repsone' => $r->json(),
                        'error' => $e->getMessage(),
                        'status' => $r->status(),
                        'body' => $r->body()  // Add this to see the full response body
                    ]), [__METHOD__, $uri]);
                })
                ->truncateExceptionsAt(1500);

        return match ($method) {

            RequestMethod::Get => $client->get($uri, $data),
        
            RequestMethod::Patch => $client->patch($uri, $data),

            RequestMethod::Post => $client->post( $uri, $data),

            default => $client->post($uri, $data)
        };
    }

    public static function makeRequestFromUrl(string $url, array $data = [], array $headers = [], RequestMethod $method = RequestMethod::Post): Response
    {
        $token = static::getAccessToken();

        $client = Http::asJson()
                ->withToken($token)
                ->withHeaders($headers)
                ->withHeader('prefer', 'return=representation') // 'return=representation'
                ->retry(3, 100, fn(Exception $exception, PendingRequest $request) => $exception instanceof ConnectionException)
                ->throw(function (Response $r, RequestException $e) use ($url) {
                    \Illuminate\Support\Facades\Log::info(collect([
                        'response' => $r,
                        'json_repsone' => $r->json(),
                        'error' => $e->getMessage(),
                        'status' => $r->status(),
                        'body' => $r->body()  // Add this to see the full response body
                    ]), [__METHOD__, $url]);
                })
                ->truncateExceptionsAt(1500);

        return match ($method) {

            RequestMethod::Get => $client->get($url, $data),
        
            RequestMethod::Patch => $client->patch($url, $data),

            RequestMethod::Post => $client->post( $url, $data),

            default => $client->post($url, $data)
        };
    }

}
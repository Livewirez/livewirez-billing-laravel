<?php 

namespace Livewirez\Billing\Lib\PayPal;

use Exception;
use Illuminate\Support\Str;
use Livewirez\Billing\Money;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Livewirez\Billing\Enums\CurrencyType;
use Livewirez\Billing\Models\BillingPlan;
use Illuminate\Http\Client\PendingRequest;
use Livewirez\Billing\Enums\RequestMethod;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\ConnectionException;
use Livewirez\Billing\Enums\SubscriptionInterval;
use Livewirez\Billing\Interfaces\ProductInterface;
use Livewirez\Billing\Lib\PayPal\SubscriptionUtils;
use Livewirez\Billing\Lib\PayPal\Enums\VaultUsagePattern;

class VaultManager
{
    protected $accessTokenResolver;

    public function __construct(callable $accessTokenResolver, protected array $config = [])
    {
        $this->accessTokenResolver = $accessTokenResolver;
        $this->config ??= config('billing.providers.paypal');
    }

    protected function getAccessToken(): string
    { 
        return call_user_func($this->accessTokenResolver);
    }

    protected function makeRequest(string $uri, array $data = [], array $headers = [], RequestMethod $method = RequestMethod::Post): Response
    {
        $token = $this->getAccessToken();

        $client = Http::baseUrl($this->config['base_url'][$this->config['environment']])
                ->asJson()
                ->withToken($token)
                ->withHeaders($headers)
                ->withHeader('prefer', 'return=representation') // 'return=representation'
                ->retry(3, 5, fn(Exception $exception, PendingRequest $request) => $exception instanceof ConnectionException)
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

            RequestMethod::Get => $client->get( $uri, $data),
        
            RequestMethod::Patch => $client->patch($uri, $data),

            RequestMethod::Post => $client->post( $uri, $data),

            default => $client->post($uri, $data)
        };
    }

    /**
     * Summary of createVaultSetupToken
     * 
     * @see https://developer.paypal.com/docs/api/payment-tokens/v3/#setup-tokens_create
     * @see https://developer.paypal.com/studio/checkout/standard/integrate/recurring
     * @see https://developer.paypal.com/docs/checkout/standard/customize/recurring-payments-module/#create-a-payment-method-token
     */
    public function createVaultSetupToken(
        VaultUsagePattern $vaultUsagePattern = VaultUsagePattern::SUBSCRIPTION_PREPAID, 
        ?BillingPlan $plan = null,
        array $data = []
    ): Response
    {
        switch($vaultUsagePattern) {
            case VaultUsagePattern::IMMEDIATE:
                // Handle immediate case
                $body = [
                    'payment_source' => [
                        'paypal' => [
                            'usage_type' => 'MERCHANT', // PLATFORM
                            'usage_pattern' => $vaultUsagePattern->value,
                            'experience_context' => [
                                'brand_name' => config('app.name'),
                                "shipping_preference" => "GET_FROM_FILE", // SET_PROVIDED_ADDRESS
                                "return_url" => $data['return_url'] ?? $this->config['payment_return_url'] ?? route($this->config['payment_return_url_name']),
                                "cancel_url" => $data['cancel_url'] ?? $this->config['payment_cancel_url'] ?? route($this->config['payment_cancel_url_name']),
                            ]
                        ]
                    ],
                ];
                break;
            case VaultUsagePattern::DEFERRED:
                // Handle deferred case
                break;
            // Add other cases as needed
            /** 
             * @see https://developer.paypal.com/studio/checkout/standard/integrate/recurring
             * @see https://developer.paypal.com/docs/checkout/standard/customize/save-payment-methods-for-recurring-payments/
             */
            case VaultUsagePattern::SUBSCRIPTION_PREPAID:
                if (! $plan) {
                    throw new \InvalidArgumentException('A BillingPlan is required for SUBSCRIPTION_PREPAID');
                }
                $body = [
                    'payment_source' => [
                        'paypal' => [
                            'usage_type' => 'MERCHANT', // PLATFORM
                            'usage_pattern' => $vaultUsagePattern->value,
                            //'billing_plan' => SubscriptionUtils::buildBillingPlan($plan, $plan->billing_product()->first()),
                            'experience_context' => [
                                'brand_name' => config('app.name'),
                                "shipping_preference" => "GET_FROM_FILE", // SET_PROVIDED_ADDRESS
                                "return_url" => $data['return_url'] ?? $this->config['subscription_return_url'] ?? route($this->config['subscription_return_url_name']),
                                "cancel_url" => $data['cancel_url'] ?? $this->config['subscription_cancel_url'] ?? route($this->config['subscription_cancel_url_name']),
                            ]
                        ]
                    ],
                ];
                break;
            default:
                $body = [];
                break;
        }

        return $this->makeRequest(
            '/v3/vault/setup-tokens', 
            $body, 
            ['PayPal-Request-ID' =>  Str::random(32)]
        );
    }
}
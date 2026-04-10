<?php 

namespace Livewirez\Billing\Lib\PayPal;

use Exception;
use DateTimeInterface;
use Illuminate\Support\Str;
use Livewirez\Billing\Money;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use GuzzleHttp\Promise\PromiseInterface;
use Livewirez\Billing\Enums\CurrencyType;
use Livewirez\Billing\Models\BillingPlan;
use Illuminate\Http\Client\PendingRequest;
use Livewirez\Billing\Enums\RequestMethod;
use Illuminate\Http\Client\RequestException;
use Livewirez\Billing\Enums\PaymentProvider;
use Livewirez\Billing\Models\BillingPlanPrice;
use Illuminate\Http\Client\ConnectionException;
use Livewirez\Billing\Enums\SubscriptionInterval;
use Livewirez\Billing\Interfaces\ProductInterface;
use Livewirez\Billing\Lib\PayPal\SubscriptionUtils;

class SubscriptionsManager
{
    public function __construct(protected array $config = [])
    {
       $this->config = $config !== [] ? $config : config('billing.providers.paypal');
    }

    protected function getAccessToken(): string
    { 
        return Cache::remember('paypal_access_token', $this->config['expires_in'], function (): string {
            $response = Http::baseUrl($this->config['base_url'][$this->config['environment']])
                ->withBasicAuth($this->config['client_id'], $this->config['client_secret'])
                ->asForm()
                ->retry(2, 100, fn (Exception $exception, PendingRequest $request) => $exception instanceof ConnectionException)
                ->throw()
                ->post('/v1/oauth2/token', [
                    'grant_type' => 'client_credentials',
                ]);

            Cache::put('paypal_access_token', $response->json('access_token'), $response->json('expires_in'));
            
            return $response->json('access_token');
        });
    }

    protected function makeRequest(string $uri, array $data = [], array $headers = [], RequestMethod $method = RequestMethod::Post): Response | PromiseInterface
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
     * @source https://developer.paypal.com/docs/api/subscriptions/v1/#subscriptions_create
     * 
     * @param  \Livewirez\Billing\Models\BillingPlan $plan
     * @param \Livewirez\Billing\Models\BillingPlanPrice $planPrice
     * @return Response
     */
    public function createSubscription(BillingPlan $plan, BillingPlanPrice $planPrice, DateTimeInterface $start): Response
    {
        $planProviderMetadata = $planPrice->billing_plan_payment_provider_information()->where([
            'payment_provider' => PaymentProvider::PayPal
        ])->firstOr(fn () => $plan->billing_plan_payment_provider_information()->where([
            'payment_provider' => PaymentProvider::PayPal
        ])->firstOrFail());
    
        $useTrialDays = $plan->trial_days > 0;

        $body = [
            'plan_id' => $planProviderMetadata->payment_provider_plan_id,
            'quantity' => (string) 1,
            // 'subscriber' => !empty($payer) ? [
            //     'name' => [
            //         'given_name' => $payer['given_name'] ?? '',
            //         'surname' => $payer['surname'] ?? '',
            //     ],
            //     'email_address' => $payer['email_address'] ?? '',
            //     'shipping_address' => $payer['shipping_address'] ?? null,
            // ] : null,
            'application_context' => [
                'brand_name' => config('app.name'),
                'locale' => 'en-US',
                'shipping_preference' => 'NO_SHIPPING',
                'user_action' => $this->config['subscriptions']['user_action'] ?? 'CONTINUE',  // 'CONTIUNE' or 'SUBSCRIBE_NOW'    
                'payment_provider' => [
                    'payer_selected' => 'PAYPAL',
                    // 'IMMEDIATE_PAYMENT_REQUIRED' or 'UNRESTRICTED'
                    'payee_preferred' => $this->config['subscriptions']['payment_method_payee_preferred'] ?? 'IMMEDIATE_PAYMENT_REQUIRED', 
                ],
                'return_url' => $this->config['subscription_return_url'] ?? route($this->config['subscription_return_url_name']),
                'cancel_url' => $this->config['subscription_cancel_url'] ?? route($this->config['subscription_cancel_url_name']),
            ],
            ...$useTrialDays ? [
                'start_time' => $start->format('Y-m-d\TH:i:s\Z') , // Start at a future date (must be future date) but if undefined will charge now otherwise charge at that future date
            ] : []
        ];

        return $this->makeRequest('/v1/billing/subscriptions', $body, ['PayPal-Request-Id' => Str::random(32)]);
    }

    public function getSubscription(string $providerSubscriptionId): Response
    {
        return $this->makeRequest("/v1/billing/subscriptions/{$providerSubscriptionId}", method: RequestMethod::Get);
    }

    public function getSubscriptions(): Response
    {
        return $this->makeRequest('/v1/billing/subscriptions?sort_by=create_time&sort_order=desc', method: RequestMethod::Get);
    }

    public function listSubscriptions(): Response
    {
        return $this->getSubscriptions();
    }

    /**
     * Update an existing subscription.
     *
     * @source https://developer.paypal.com/docs/api/subscriptions/v1/#subscriptions_patch
     *
     *   | Attribute or Object                                                 | Operations     |
     *   |---------------------------------------------------------------------|----------------|
     *   | billing_info.outstanding_balance                                    | replace        |
     *   | custom_id                                                           | add, replace   |
     *   | plan.billing_cycles[@sequence==n].pricing_scheme.fixed_price        | add, replace   |
     *   | plan.billing_cycles[@sequence==n].pricing_scheme.tiers              | replace        |
     *   | plan.billing_cycles[@sequence==n].total_cycles                      | replace        |
     *   | plan.payment_preferences.auto_bill_outstanding                      | replace        |
     *   | plan.payment_preferences.payment_failure_threshold                  | replace        |
     *   | plan.taxes.inclusive                                                | add, replace   |
     *   | plan.taxes.percentage                                               | add, replace   |
     *   | shipping_amount                                                     | add, replace   |
     *   | start_time                                                          | replace        |
     *   | subscriber.shipping_address                                         | add, replace   |
     * 
     * @param string $providerSubscriptionId The PayPal subscription ID to update.
     * @param array $patchOperations Array of patch operations (e.g., replace, add, remove) as per JSON Patch spec.
     * @return Response
     */
    public function updateSubscription(string $providerSubscriptionId, array $patchOperations): Response
    {
        $headers = [
            'PayPal-Request-Id' => Str::random(32),
        ];

        // Validate allowed paths for CREATED or ACTIVE plans
        $allowedPathsMap = [
            '/billing_info/outstanding_balance' => 'replace',
            '/custom_id' => ['add', 'replace'],
            '/plan/billing_cycles/@sequence=={n}/pricing_scheme/fixed_price' => ['add', 'replace'],
            '/plan/billing_cycles/@sequence=={n}/pricing_scheme/tiers' => 'replace',
            '/plan/billing_cycles/@sequence=={n}/total_cycles' => 'replace',
            '/plan/payment_preferences/auto_bill_outstanding' => 'replace',
            '/plan/payment_preferences/payment_failure_threshold' => 'replace',
            '/plan/taxes/inclusive' => ['add', 'replace'],
            '/plan/taxes/percentage' => ['add', 'replace'],
            '/shipping_amount' => ['add', 'replace'],
            '/start_time' => 'replace',
            '/subscriber/shipping_address' => ['add', 'replace'],
            '/subscriber/payment_source' => 'replace',
        ];

        $updates = array_filter($patchOperations, function (array $update) use ($allowedPathsMap): bool {
            if (
                !isset($update['path']) ||
                !isset($update['op']) ||
                !array_key_exists('value', $update)
            ) {
                return false;
            }

            $path = $update['path'] ?? '';
            $op = $update['op'] ?? '';

            foreach ($allowedPathsMap as $allowedPath => $allowedOps) {
                // Wildcard match for @sequence=={n}
                if (str_contains($allowedPath, '@sequence==') && str_contains($path, '@sequence==')) {
                    $normalizedAllowedPath = preg_replace('/@sequence==\d+/', '@sequence=={n}', $allowedPath);
                    $normalizedPath = preg_replace('/@sequence==\d+/', '@sequence=={n}', $path);
                } else {
                    $normalizedAllowedPath = $allowedPath;
                    $normalizedPath = $path;
                }

                if ($normalizedPath === $normalizedAllowedPath) {
                    // Allow single string or array of allowed operations
                    if (is_array($allowedOps)) {
                        return in_array($op, $allowedOps);
                    }
                    return $op === $allowedOps;
                }
            }

            return false;
        });

        return $this->makeRequest(
            "/v1/billing/subscriptions/{$providerSubscriptionId}",
            $updates,
            $headers,
            RequestMethod::Patch
        );
    }

    /**
     * @source https://developer.paypal.com/docs/api/subscriptions/v1/#subscriptions_revise
     * 
     * Updates the quantity of the product or service in a subscription. 
     * You can also use this method to switch the plan and update the shipping_amount, shipping_address values for the subscription.
     *  This type of update requires the buyer's consent.
     * 
     * @param string $providerSubscriptionId
     * @param \Livewirez\Billing\Models\BillingPlanPrice $planPrice
     * @param array $revisions
     * @return Response
     */
    public function reviseSubscription(string $providerSubscriptionId, BillingPlanPrice $planPrice, array $revisions = []): Response
    {
        $planProviderMetadata = $planPrice->billing_plan_payment_provider_information()->where([
            'payment_provider' => PaymentProvider::PayPal
        ])->firstOrFail();

        $body = [
            'plan_id' => $planProviderMetadata->payment_provider_plan_id,
            'quantity' => (string) 1,
            'subscriber' => !empty($revisions) ? [
                'name' => [
                    'given_name' => $revisions['given_name'] ?? '',
                    'surname' => $revisions['surname'] ?? '',
                ],
                'email_address' => $revisions['email_address'] ?? '',
                'shipping_address' => $revisions['shipping_address'] ?? null,
            ] : null,
            'application_context' => [
                'brand_name' => config('app.name'),
                'locale' => 'en-US',
                'shipping_preference' => 'NO_SHIPPING',
                //'user_action' => $this->config['subscriptions']['user_action'] ?? 'CONTINUE', 
                'payment_provider' => [
                    'payer_selected' => 'PAYPAL',
                    'payee_preferred' => $this->config['subscriptions']['payment_method_payee_preferred'] ?? 'IMMEDIATE_PAYMENT_REQUIRED',
                ],
                'return_url' => $this->config['subscription_return_url'] ?? route($this->config['subscription_return_url_name']),
                'cancel_url' => $this->config['subscription_cancel_url'] ?? route($this->config['subscription_cancel_url_name']),
            ],
        ];

        return $this->makeRequest(
            "/v1/billing/subscriptions/{$providerSubscriptionId}/revise", 
            $body, 
            ['PayPal-Request-Id' => Str::random(32)]
        );
    }

    public function suspendSubscription(string $providerSubscriptionId, array $data = [ "reason" => "User has decided to suspend subscription"]): Response
    {
        return $this->makeRequest("/v1/billing/subscriptions/{$providerSubscriptionId}/suspend", $data);
    }


    public function cancelSubscription(string $providerSubscriptionId, array $data = ['reason' => 'User has decided to cancel subscription']): Response
    {
        return $this->makeRequest("/v1/billing/subscriptions/{$providerSubscriptionId}/cancel", $data);
    }

    public function activateSubscription(string $providerSubscriptionId, array $data = ['reason' => 'Activating the subscription']): Response
    {
        return $this->makeRequest("/v1/billing/subscriptions/{$providerSubscriptionId}/activate", $data);
    }

    public function captureAuthorizedPaymentOnSubscription(
        string $providerSubscriptionId, 
        string $amount, 
        string $currency, 
        ?string $note = null
    ): Response
    {
        return $this->makeRequest(
            "/v1/billing/subscriptions/{$providerSubscriptionId}/capture",
            [
                'note' => $note ?? 'Charging as the balance reached the limit', 
                'capture_type' => "OUTSTANDING_BALANCE",
                'amount'=> [
                    'currency_code'=> $currency,
                    'value'=> $amount
                ]
            ],
            ['PayPal-Request-Id' => Str::random(32)]
        );
    }


    public function getTransactionsForSubscription(
        string $providerSubscriptionId,
        DateTimeInterface $startDate,
        DateTimeInterface $endDate
    ): Response
    {
        return $this->makeRequest(
            "/v1/billing/subscriptions/{$providerSubscriptionId}/transactions",
            [
                'start_time' => $startDate->format('Y-m-d\TH:i:s\Z'), 
                'end_time' => $endDate->format('Y-m-d\TH:i:s\Z'), 
            ],
            method: RequestMethod::Get
        );
    }
}
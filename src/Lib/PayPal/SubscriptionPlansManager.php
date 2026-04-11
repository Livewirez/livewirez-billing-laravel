<?php

namespace Livewirez\Billing\Lib\PayPal;

use Exception;
use Livewirez\Billing\Money;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Query\Builder;
use GuzzleHttp\Promise\PromiseInterface;
use Livewirez\Billing\Enums\ProductType;
use Livewirez\Billing\Enums\CurrencyType;
use Livewirez\Billing\Models\BillingPlan;

use Illuminate\Http\Client\PendingRequest;
use Livewirez\Billing\Enums\RequestMethod;
use Illuminate\Http\Client\RequestException;
use Livewirez\Billing\Enums\PaymentProvider;
use Livewirez\Billing\Models\BillingProduct;
use Livewirez\Billing\Models\BillingPlanPrice;
use Illuminate\Http\Client\ConnectionException;
use Livewirez\Billing\Enums\SubscriptionInterval;

use Livewirez\Billing\Interfaces\ProductInterface;
use function Livewirez\Billing\formatAmountUsingCurrency;
use Livewirez\Billing\Models\BillingPlanPaymentProviderInformation;


class SubscriptionPlansManager
{
    public function __construct(protected array $config = [])
    {
        $this->config = $config !== [] ? $config : config('billing.providers.paypal');
    }

    protected function getAccessToken(): string
    { 
        return Cache::remember('paypal_access_token', $this->config['expires_in'], function (): string {
            $response = Http::withBasicAuth($this->config['client_id'], $this->config['client_secret'])
                ->asForm()
                ->retry(2, 100, fn (Exception $exception, PendingRequest $request) => $exception instanceof ConnectionException)
                ->throw()
                ->post($this->config['base_url'][$this->config['environment']] . '/v1/oauth2/token', [
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
     * Create PayPal products for all service-type products in the database.
     *
     * @return array Array of created PayPal product IDs
     * @throws RequestException
     */
    public function createProducts(): array
    {
        $products = BillingProduct::where('product_type', ProductType::SERVICE)->get();
        $createdProductIds = [];

        foreach ($products as $product) {
            if ($product instanceof ProductInterface) {
                $paypalProduct = $this->createProduct($product);
                if ($paypalProduct && isset($paypalProduct['id'])) {
                    $createdProductIds[] = $paypalProduct['id'];
                }
            }
        }

        return $createdProductIds;
    }

    /**
     * Create a single PayPal product from a ProductInterface instance.
     *
     * @param ProductInterface $product
     * @return array|null PayPal product response or null on failure
     * @throws RequestException
     */
    public function createProduct(BillingProduct $product): ?array
    {
        $payload = [
            'name' => $product->getName(),
            'description' => $product->getDescription() ?? 'No description provided for this product',
            'type' => $product->getProductType()->name,
            'category' => $product->getProductCategory()->value,
            'image_url' => $product->getImageUrl(),
            'home_url' => $product->getUrl(),
        ];

        // Remove null values from payload
        $payload = array_filter($payload, fn($value) => !is_null($value));

        $response = $this->makeRequest(
            '/v1/catalogs/products',
            $payload,
            ['PayPal-Request-Id' => 'PRODUCT-' . uniqid()]
        );

        if ($response->successful()) {
            $paypalProduct = $response->json();

            static::savePaypalMetadata($product, $paypalProduct);

            return $paypalProduct;
        }

        return null;
    }

    /**
     * Retrieve details of a specific PayPal product.
     *
     * @param string $paypalProductId
     * @return array|null PayPal product details or null on failure
     * @throws RequestException
     */
    public function getProduct(string $paypalProductId): ?array
    {
        $response = $this->makeRequest(
            '/v1/catalogs/products/' . $paypalProductId,
            method: RequestMethod::Get
        );

        return $response->successful() ? $response->json() : null;
    }

    /**
     * Update a PayPal product.
     *
     * @param BillingProduct $product
     * @param array $updates Array of updates in PATCH format (e.g., [['op' => 'replace', 'path' => '/description', 'value' => 'New description']])
     * @return array|null Updated PayPal product response or null on failure
     * @throws RequestException
     */
    public function updateProduct(BillingProduct $product, array $updates): ?array
    {
        $paypalProductId = $product->metadata[PaymentProvider::PayPal->value]['id'] ?? null;

        if (!$paypalProductId) {
            return null;
        }

        $response = $this->makeRequest(
            '/v1/catalogs/products/' . $paypalProductId,
            $updates,
            method: RequestMethod::Patch
        );

        if ($response->successful()) {
            $paypalProduct = $response->json();

            static::savePaypalMetadata($product, $paypalProduct);

            return $paypalProduct;
        }

        return null;
    }

    /**
     * List all PayPal products.
     *
     * @return array|null Array of PayPal products or null on failure
     * @throws RequestException
     */
    public function listProducts(): ?array
    {
        $response = $this->makeRequest(
            '/v1/catalogs/products',
            method: RequestMethod::Get
        );

        return $response->successful() ? $response->json() : null;
    }

    /**
     * Save PayPal response data to product metadata
     */
    public static function savePaypalMetadata(BillingProduct $product, array $paypalData): void
    {
        $currentMetadata = $product->metadata ?? [];
        
        $product->update([
            'metadata' => array_merge($currentMetadata, [
                PaymentProvider::PayPal->value => $paypalData
            ])
        ]);
    }

    /**
     * Get PayPal product ID from metadata
     */
    public function getPaypalProductId(BillingProduct $product): ?string
    {
        return $product->metadata[PaymentProvider::PayPal->value]['id'] ?? null;
    }

    /**
     * Check if product exists in PayPal (using metadata)
     */
    public function hasPaypalProduct(BillingProduct $product): bool
    {
        return !empty($this->getPaypalProductId($product));
    }

    /**
     * Get PayPal plan ID from metadata
     */
    public function getPaypalPlanId(BillingPlan $plan): ?string
    {
        return $plan->metadata[PaymentProvider::PayPal->value]['id'] ?? null;
    }

    /**
     * Check if plan exists in PayPal (using metadata)
     */
    public function hasPaypalPlan(BillingPlan $plan): bool
    {
        return !empty($this->getPaypalPlanId($plan));
    }

    public function createPlans(BillingProduct $product)
    {
        $paypalProductId = $product->metadata[PaymentProvider::PayPal->value]['id'] ?? null;

        if (!$paypalProductId) {
            return null;
        }

        $plans = $product->billing_plans()->get();

        $responses = Http::pool(
            fn (Pool $pool) => $plans->map(
                function (BillingPlan $plan) use ($pool, $product, $paypalProductId) {

                    $payload = [
                        'product_id' => $paypalProductId,
                        'name' => $plan->name,
                        'description' => $plan->description ?? 'No description provided',
                        'status' => $plan->is_active ? 'ACTIVE' : 'INACTIVE',
                        'billing_cycles' => $this->buildBillingCycles($plan, $product),
                        'payment_preferences' => [
                            'auto_bill_outstanding' => true,
                            'setup_fee' => [
                                'value' => Money::formatAmountUsingCurrency($product->getPrice(), $product->getCurrencyCode()),
                                'currency_code' => $product->getCurrencyCode(),
                            ],
                            'setup_fee_failure_action' => 'CONTINUE',
                            'payment_failure_threshold' => 3,
                        ],
                        'taxes' => [
                            'percentage' => Money::formatAmountUsingCurrency($product->getTax(), $product->getCurrencyCode()),
                            'inclusive' => $product->tax_model === 'include',
                        ],
                    ];



                    return  $pool->as((string) $plan->id)->withHeaders([
                        'Authorization' => 'Bearer ' . $this->getAccessToken(),
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                        'PayPal-Request-Id' => 'PLAN-' . uniqid(),
                        'Prefer' => 'return=representation',
                    ])
                    ->retry(2, 100, fn (Exception $exception, PendingRequest $request) => $exception instanceof ConnectionException)
                    ->throw(function (Response $r, RequestException $e) {
                        \Illuminate\Support\Facades\Log::info(collect([
                            'response' => $r,
                            'json_repsone' => $r->json(),
                            'error' => $e->getMessage(),
                            'error_code' => $e->getCode(),
                            'status' => $r->status(),
                            'body' => $r->body()  // Add this to see the full response body
                        ]), [__METHOD__]);
                    })
                    ->post($this->config['base_url'][$this->config['environment']] . '/v1/billing/plans', $payload);
                }
            )->toArray()
        );

        foreach($responses as $key => $response) {

            if ($response instanceof Response && $response->successful()) {
                $paypalPlan = $response->json();

                $this->updatePlanWithProviderMetadata(BillingPlan::where(['id' => (int) $key])->sole(), $product, $paypalPlan);
            }
        }

        return array_map(
            function (mixed $response) {
                if ($response instanceof Response) return $response->json();
                if ($response instanceof RequestException) return [
                    'error' => get_class($response), 
                    'msg' => $response->getMessage(), 
                    'data' => $response->response->json()
                ];
                if ($response instanceof ConnectionException) return [
                    'error' => get_class($response), 
                    'msg' =>$response->getMessage()
                ];

                return $response;
            },
            $responses
        );
    }

    protected function updatePlanWithProviderMetadata(BillingPlan $plan, BillingProduct $product, array $data): BillingPlanPaymentProviderInformation 
    {
        return DB::transaction(function () use ($plan, $product, $data): BillingPlanPaymentProviderInformation {

            // Update plan metadata with PayPal response
            $plan->update([
                'billing_product_id' => $product->getId(),
                'metadata' => array_merge($plan->metadata ?? [], [
                    PaymentProvider::PayPal->value => $data
                ])
            ]);

            $plan_provider_metadata = $plan->billing_plan_payment_provider_information()->updateOrCreate([
                'payment_provider' => PaymentProvider::PayPal,
                'payment_provider_plan_id' => $data['id']
            ], [
                'status' => $data['status'] ?? 'INACTIVE',
                'is_active' => isset($data['status']) && $data['status'] === 'ACTIVE',
                'metadata' => array_merge($plan->metadata ?? [], $data)
            ]);


            return $plan_provider_metadata;
        });
    }

    protected function updatePlanProviderMetadata(BillingPlan $plan,  array $data): BillingPlanPaymentProviderInformation
    {
        $plan_provider_metadata = $plan->billing_plan_payment_provider_information()->updateOrCreate([
            'payment_provider' => PaymentProvider::PayPal,
            'payment_provider_plan_id' =>  $data['id']
        ], [
            ...isset($data['status']) ? ['status' => $data['status']] : [],
            ...isset($data['is_active']) ? ['is_active' => $data['is_active']] : [],
            'metadata' => array_merge($plan->metadata ?? [], $data)
        ]);

        return $plan_provider_metadata;
    }

    /**
     * Create a PayPal billing plan for a given plan and product.
     *
     * @param BillingPlan $plan
     * @param BillingProduct $product
     * @return array|null PayPal plan response or null on failure
     * @throws RequestException
     */
    public function createPlan(BillingPlan $plan, BillingProduct $product): ?array
    {
        if ($plan->billing_product_id !== $product->id) return null;

        $paypalProductId = $product->metadata[PaymentProvider::PayPal->value]['id'] ?? null;

        if (!$paypalProductId) {
            return null;
        }

        $payload = [
            'product_id' => $paypalProductId,
            'name' => $plan->name,
            'description' => $plan->description ?? 'No description provided',
            'status' => $plan->is_active ? 'ACTIVE' : 'INACTIVE',
            'billing_cycles' => $this->buildBillingCycles($plan, $product),
            'payment_preferences' => [
                'auto_bill_outstanding' => true,
                'setup_fee' => [
                    'value' => Money::formatAmountUsingCurrency($product->getPrice(), $product->getCurrencyCode()),
                    'currency_code' => $product->getCurrencyCode(),
                ],
                'setup_fee_failure_action' => 'CONTINUE',
                'payment_failure_threshold' => 3,
            ],
            'taxes' => [
                'percentage' => Money::formatAmountUsingCurrency($product->getTax(), $product->getCurrencyCode()),
                'inclusive' => $product->tax_model === 'include',
            ],
        ];

        // Remove null values from payload
        $payload = array_filter($payload, fn($value) => !is_null($value), ARRAY_FILTER_USE_BOTH);

        $response = $this->makeRequest(
            '/v1/billing/plans',
            $payload,
            [ 'PayPal-Request-Id' => 'PLAN-' . uniqid()]
        );

        if ($response->successful()) {
            $paypalPlan = $response->json();

            $this->updatePlanWithProviderMetadata($plan, $product, $paypalPlan);

            return $paypalPlan;
        }

        return null;
    }

    /**
     * List all PayPal billing plans.
     *
     * @return array|null Array of PayPal plans or null on failure
     * @throws RequestException
     */
    public function listPlans(): ?array
    {
        $response = $this->makeRequest(
            '/v1/billing/plans?sort_by=create_time&sort_order=desc',
            method: RequestMethod::Get
        );

        return $response->successful() ? $response->json() : null;
    }

    /**
     * Retrieve details of a specific PayPal billing plan.
     *
     * @param string $paypalPlanId
     * @return array|null PayPal plan details or null on failure
     * @throws RequestException
     */
    public function getPlan(string $paypalPlanId): ?array
    {
        $response = $this->makeRequest(
            '/v1/billing/plans/' . $paypalPlanId,
            method: RequestMethod::Get
        );

        return $response->successful() ? $response->json() : null;
    }

    public function showPlanDetails(string $paypalPlanId): ?array
    {
        return $this->getPlan($paypalPlanId);
    }

    /**
     * Update a PayPal billing plan. 
     * 
     * @source https://developer.paypal.com/docs/api/subscriptions/v1/#plans_patch
     *
     * @param BillingPlan $plan
     * @param array $updates Array of updates in PATCH format (e.g., [['op' => 'replace', 'path' => '/name', 'value' => 'New Name']])
     * @return array|null Updated PayPal plan response or null on failure
     * @throws RequestException
     */
    public function updatePlan(BillingPlan $plan, array $updates): ?array
    {
        $paypalPlanId = $plan->metadata[PaymentProvider::PayPal->value]['id'] ?? null;

        if (!$paypalPlanId) {
            return null;
        }

        // For INACTIVE plans, restrict updates to status only
        if (!$plan->is_active) {
            $updates = array_filter($updates, fn($update) => $update['path'] === '/status');
            if (empty($updates)) {
                return null; // No valid updates for INACTIVE plan
            }
        }

        // Validate allowed paths for CREATED or ACTIVE plans
        $allowedPaths = [
            '/description',
            '/payment_preferences/auto_bill_outstanding',
            '/taxes/percentage',
            '/payment_preferences/payment_failure_threshold',
            '/payment_preferences/setup_fee',
            '/payment_preferences/setup_fee_failure_action',
            '/name',
            '/status',
        ];

        $updates = array_filter($updates, function ($update) use ($allowedPaths) {
            return in_array($update['path'], $allowedPaths) 
            && in_array($update['op'], ['add', 'remove', 'replace', 'move', 'copy', 'test']);
        });

        if (empty($updates)) {
            return null; // No valid updates provided
        }

        $response = $this->makeRequest(
            '/v1/billing/plans/' . $paypalPlanId,
            $updates,
            method: RequestMethod::Patch
        );

        if ($response->successful()) {
            $paypalPlan = $response->json();

            // Update plan metadata with new PayPal response
            $plan->update([
                'metadata' => array_merge($plan->metadata ?? [], [
                    PaymentProvider::PayPal->value => $paypalPlan
                ])
            ]);

            $ppm = $this->updatePlanProviderMetadata($plan, $paypalPlan);

            // Update local plan attributes if relevant
            foreach ($updates as $update) {
                if ($update['op'] === 'replace' && $update['path'] === '/name') {
                    $plan->update(['name' => $update['value']]);
                } elseif ($update['op'] === 'replace' && $update['path'] === '/description') {
                    $plan->update(['description' => $update['value']]);
                } elseif ($update['op'] === 'replace' && $update['path'] === '/status') {
                    $plan->update(['is_active' => $is_active =  $update['value'] === 'ACTIVE']);
                    $ppm->update(['is_active' => $is_active, 'status' => $update['value']]);
                }
            }

            return $paypalPlan;
        }

        return null;
    }

     /**
     * Activate a PayPal billing plan.
     *
     * @param BillingPlan $plan
     * @return bool True on success, false on failure
     * @throws RequestException
     */
    public function activatePlan(BillingPlan $plan): bool
    {
        $paypalPlanId = $plan->metadata[PaymentProvider::PayPal->value]['id'] ?? null;

        if (!$paypalPlanId) {
            return false;
        }

        $response = $this->makeRequest(
            '/v1/billing/plans/' . $paypalPlanId . '/activate',
        );

        if ($response->successful()) {
            // Fetch updated plan details to store in metadata
            $updatedPlan = $this->getPlan($paypalPlanId);

            if ($updatedPlan) {
                $plan->update([
                    'is_active' => true,
                    'metadata' => array_merge($plan->metadata ?? [], [
                        PaymentProvider::PayPal->value => $updatedPlan
                    ])
                ]);

                $ppm = $this->updatePlanProviderMetadata($plan, [...$updatedPlan, 'status' => 'ACTIVE', 'is_active' => true]);
            }

            return true;
        }

        return false;
    }

    /**
     * Deactivate a PayPal billing plan.
     *
     * @param BillingPlan $plan
     * @return bool True on success, false on failure
     * @throws RequestException
     */
    public function deactivatePlan(BillingPlan $plan): bool
    {
        $paypalPlanId = $plan->metadata[PaymentProvider::PayPal->value]['id'] ?? null;

        if (!$paypalPlanId) {
            return false;
        }

        $response = $this->makeRequest(
           '/v1/billing/plans/' . $paypalPlanId . '/deactivate'
        );

        if ($response->successful()) {
            // Fetch updated plan details to store in metadata
            $updatedPlan = $this->getPlan($paypalPlanId);

            if ($updatedPlan) {
                $plan->update([
                    'is_active' => false,
                    'metadata' => array_merge($plan->metadata ?? [], [
                        PaymentProvider::PayPal->value => $updatedPlan
                    ])
                ]);

                $ppm = $this->updatePlanProviderMetadata($plan, [...$updatedPlan, 'status' => 'INACTIVE', 'is_active' => false]);
            }

            return true;
        }

        return false;
    }

    /**
     * Update pricing schemes for a PayPal billing plan.
     * 
     * @source https://developer.paypal.com/docs/api/subscriptions/v1/#plans_update-pricing-schemes
     *
     * @param BillingPlan $plan
     * @param array $pricingSchemes Array of pricing schemes (e.g., [['billing_cycle_sequence' => 1, 'pricing_scheme' => [...]]])
     * @return bool True on success, false on failure
     * @throws RequestException
     */
    public function updatePricing(BillingPlan $plan, array $pricingSchemes): bool
    {
        $paypalPlanId = $plan->metadata[PaymentProvider::PayPal->value]['id'] ?? null;

        if (!$paypalPlanId) {
            return false;
        }

        // Validate pricing schemes
        $payload = [
            'pricing_schemes' => array_map(function (array $scheme) use ($plan) {
                $priceRecord = $plan->billing_prices()
                    ->where('interval', $scheme['billing_cycle_sequence'] === 1 ? SubscriptionInterval::MONTHLY : SubscriptionInterval::YEARLY)
                    ->where('currency', $plan->billing_prices()->first()->currency ?? 'USD')
                    ->first();

                return [
                    'billing_cycle_sequence' => $scheme['billing_cycle_sequence'],
                    'pricing_scheme' => [
                        'fixed_price' => [
                            'value' => $priceRecord ? Money::formatAmountUsingCurrency($priceRecord->amount, $priceRecord->currency) : $scheme['pricing_scheme']['fixed_price']['value'],
                            'currency_code' => $priceRecord ? $priceRecord->currency : $scheme['pricing_scheme']['fixed_price']['currency_code'],
                        ],
                        // Include tiers or pricing_model if provided
                        'pricing_model' => $scheme['pricing_scheme']['pricing_model'] ?? 'FIXED',
                        'tiers' => $scheme['pricing_scheme']['tiers'] ?? [],
                    ],
                ];
            }, $pricingSchemes),
        ];

        $response = $this->makeRequest(
            '/v1/billing/plans/' . $paypalPlanId . '/update-pricing-schemes',
            $payload
        );

        if ($response->successful()) {
            // Fetch updated plan details to store in metadata
            $updatedPlan = $this->getPlan($paypalPlanId);

            if ($updatedPlan) {
                $plan->update([
                    'metadata' => array_merge($plan->metadata ?? [], [
                        PaymentProvider::PayPal->value => $updatedPlan
                    ])
                ]);

                $ppm = $this->updatePlanProviderMetadata($plan, $updatedPlan);
            }

            return true;
        }

        return false;
    }
    
    /**
     * Build billing cycles for a PayPal plan based on the plan and product.
     *
     * @param BillingPlan $plan
     * @param ProductInterface $product
     * @return array
     */
    public function buildBillingCycles(BillingPlan $plan, ProductInterface $product): array
    {
        $billingCycles = [];

        // Load plan prices
        $prices = $plan->billing_prices()->where([
            'currency' => $product->getCurrencyCode(),
        ])->get();

        // Check for trial period in features
        $features = $plan->features ?? [];
        $trialPeriods = $features['trial_periods'] ?? []; // Array of trial configurations
        if (!is_array($trialPeriods)) {
            $trialPeriods = $features['trial_period'] ? [$features['trial_period']] : [];
        }

        $sequence = 1;

        // Add trial billing cycles if applicable
        foreach ($trialPeriods as $index => $trial) {
            $trialPrice = $trial['price'] ?? 0;
            $trialCycles = $trial['cycles'] ?? 1;
            $trialInterval = $trial['interval'] ?? SubscriptionInterval::MONTHLY;

            if ($trialPrice >= 0 && $trialCycles > 0) {
                $trialPriceRecord = $prices->where('interval', $trialInterval)->first();
                $trialAmount = $trialPriceRecord ? $trialPriceRecord->amount : $trialPrice;

                $billingCycles[] = [
                    'frequency' => [
                        'interval_unit' => $trialInterval === SubscriptionInterval::YEARLY ? 'YEAR' : 'MONTH',
                        'interval_count' => 1,
                    ],
                    'tenure_type' => 'TRIAL',
                    'sequence' => $sequence++,
                    'total_cycles' => $trialCycles,
                    'pricing_scheme' => [
                        'fixed_price' => [
                            'value' => Money::formatAmountUsingCurrency($trialAmount, $product->getCurrencyCode()),
                            'currency_code' => $product->getCurrencyCode(),
                        ],
                    ],
                ];
            }
        }

        // Add a single regular billing cycle
        $regularInterval = SubscriptionInterval::MONTHLY; // Default to MONTHLY, can be configurable
        $priceRecord = $prices->where('interval', $regularInterval)->first();

        if (!$priceRecord && $prices->where('interval', SubscriptionInterval::YEARLY)->first()) {
            $regularInterval = SubscriptionInterval::YEARLY; // Fallback to YEARLY if no MONTHLY
            $priceRecord = $prices->where('interval', $regularInterval)->first();
        }

        if ($priceRecord) {
            $billingCycles[] = [
                'frequency' => [
                    'interval_unit' => match ($regularInterval) {
                        SubscriptionInterval::YEARLY => 'YEAR',
                        SubscriptionInterval::MONTHLY => 'MONTH',
                        SubscriptionInterval::WEEKLY => 'WEEK',
                        SubscriptionInterval::DAILY => 'DAY',
                        default => 'MONTH'
                    },
                    /**
                     * @source https://developer.paypal.com/docs/api/subscriptions/v1/#plans_create!ct=application/json&path=billing_cycles/frequency/interval_count&t=request
                     * 
                     * Default: 1
                     * 
                     * The number of intervals after which a subscriber is billed. For example, 
                     * if the interval_unit is DAY with an interval_count of 2, the subscription is billed once every two days. 
                     * The following table lists the maximum allowed values for the interval_count for each interval_unit:
                     * 
                     * 
                     * |
                     * |Interval unit	Maximum interval count
                     * |DAY	             365
                     * |WEEK	         52
                     * |MONTH	         12
                    *  |YEAR	         1
                     */
                    'interval_count' => 1,
                ],
                'tenure_type' => 'REGULAR',
                'sequence' => $sequence++,
                'total_cycles' => 0, // Unlimited cycles
                'pricing_scheme' => [
                    'fixed_price' => [
                        'value' => Money::formatAmountUsingCurrency($priceRecord->amount, $priceRecord->currency),
                        'currency_code' => $priceRecord->currency,
                    ],
                ],
            ];
        }

        return $billingCycles;
    }
}
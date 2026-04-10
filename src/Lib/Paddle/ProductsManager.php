<?php 

namespace Livewirez\Billing\Lib\Paddle;

use Exception;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Client\Response;
use Livewirez\Billing\Enums\ProductType;
use Livewirez\Billing\Enums\PaymentProvider;
use Livewirez\Billing\Models\BillingProduct;
use Livewirez\Billing\Enums\ApiProductTypeKey;
use Livewirez\Billing\Models\BillingPlanPrice;
use Livewirez\Billing\Enums\SubscriptionInterval;
use Livewirez\Billing\Lib\Paddle\Exceptions\PaddleApiError;

class ProductsManager
{

    //'product_type' => 'one-time'

     /**
     * @see https://developer.paddle.com/api-reference/prices/list-prices
     */
    public static function getProducts(array $query = [])
    {
        try {
            $response = Paddle::api("GET", "products", [
                ...$query,
                'custom_data[metadata][product_type]' => ApiProductTypeKey::ONE_TIME->value
            ]);

           return $response->json();
        } catch (PaddleApiError $e) {
            throw new PaddleApiError($e->getMessage(), 400);
        }
    }

    public static function createProduct(BillingProduct $billingProduct, ?callable $pathResolver = null)
    {
        if ($billingProduct->type === ProductType::SERVICE) throw new Exception(
            sprintf(
                'Please use the %s::%s method for subscription products.',
                static::class,
                'createSubscriptionProduct'
            )
        );

        if (! $path = filter_var($billingProduct->thumbnail, FILTER_VALIDATE_URL)) {

            if (!is_callable($pathResolver)) {
                throw new InvalidArgumentException('Invalid path resolver');
            }

            $path = $pathResolver($billingProduct->thumbnail);
        }

        $payload = [
            'name' => $billingProduct->name,
            'tax_category' => 'standard',
            'description' => $billingProduct->description,
            'image_url' => $path,
            'custom_data' => [
                'url' => $billingProduct->url,
                'metadata' => [
                    'billing_product_id' => $billingProduct->billing_product_id,
                    'billing_product' => $billingProduct->id,
                    'product_type' => ApiProductTypeKey::ONE_TIME->value
                ]
            ],
        ];

        try {
            $response = Paddle::api("POST", "products", $payload);

            $data = $response->json('data');

            $pricePayload = [
                'description' => Str::title( $billingProduct->description ?? $billingProduct->name) . " price one time",
                'name' => Str::title($billingProduct->name) . ' Price',
                'product_id' => $response->json('data.id'),
                'type' => 'standard',
                'unit_price' => [
                    'amount' => (string) $billingProduct->price,
                    'currency_code' => $billingProduct->currency,
                ],
                'billing_cycle' => null,
                'trial_period' => null,
                'tax_mode' => 'account_setting',
                'custom_data' => [
                    'url' => $billingProduct->url,
                    'metadata' => [
                        'billing_product_name' => $billingProduct->name,
                        'billing_product_id' => $billingProduct->billing_product_id,
                        'billing_product' => $billingProduct->id,
                        'product_type' => ApiProductTypeKey::ONE_TIME->value
                    ],
                ]
            ];

            $priceResponse = Paddle::api("POST", "prices", $pricePayload);

            $priceData = $priceResponse->json('data');

            static::updateBillingProductMetadata($billingProduct, $data, [$priceData]);

            return $data;
        } catch (PaddleApiError $e) {
            throw new PaddleApiError($e->getMessage(), 400);
        }
    }

    public static function resolveFeatures(array $features): array
    {
        $resolved = [];

        foreach ($features as $key => $feature) {
            if (is_int($key) && is_string($feature)) {
                $resolved[$feature] = true;
            }
        }

        return $resolved !== [] ? $resolved : $features;
    }

    public static function poolSubscriptionProductsAndPrices(array $query = []): array
    {
        $query = [
            ...$query,
            'custom_data[metadata][product_type]' => ApiProductTypeKey::SUBSCRIPTION->value
        ];

        $callback = fn (Pool $pool, string $url, string $token) => [
            $pool->as('plans')->baseUrl($url)->withToken($token)
                ->asJson()
                ->get("/products", $query),

            $pool->as('prices')->baseUrl($url)->withToken($token)
                ->asJson()
                ->get("/prices", $query),    
        ];
            

        $responses = Paddle::apiPool($callback);

        return array_map(function (mixed $response) {

            if ($response instanceof Response && $response->successful()) {
            
                return $response->json();
            }

            return $response;
        }, $responses);
    }

    /**
     * @see https://developer.paddle.com/api-reference/prices/list-prices
     */
    public static function getSubscriptionProducts(array $query = [])
    {
        try {
            $response = Paddle::api("GET", "products", [
                ...$query,
                'custom_data[metadata][product_type]' => ApiProductTypeKey::SUBSCRIPTION->value
            ]);

           return $response->json();
        } catch (PaddleApiError $e) {
            throw new PaddleApiError($e->getMessage(), 400);
        }
    }

    public static function getSubscriptionProduct(string $product_id)
    {
        try {
            $response = Paddle::api("GET", "products/{$product_id}");

           return $response->json();
        } catch (PaddleApiError $e) {
            throw new PaddleApiError($e->getMessage(), 400);
        }
    }

    /** 
     * @see https://developer.paddle.com/build/products/create-products-prices#build-request-create-product 
     */
    public static function createSubscriptionProduct(BillingPlanPrice $plan_price)
    {
        $plan_price->loadMissing('billing_plan.billing_product');

        $payload = [
            'name' => $plan_price->billing_plan->name . ' - ' . ucfirst(mb_strtolower($plan_price->interval->name)),
            'tax_category' => 'standard',
            'description' => $plan_price->billing_plan->description,
            'image_url' => $plan_price->billing_plan->thumbnail,
            'custom_data' => [
                'features' => static::resolveFeatures($plan_price->billing_plan->features ?? []),
                'suggested_addons' => [],
                'upgrade_description' => null,
                'metadata' => [
                    'billing_plan_name' => $plan_price->billing_plan->name,
                    'billing_plan' => $plan_price->billing_plan->id,
                    'billing_plan_id' => $plan_price->billing_plan->billing_plan_id,
                    'billing_product' => $plan_price->billing_plan->billing_product->id,
                    'billing_product_id' => $plan_price->billing_plan->billing_product->billing_product_id,
                    'billing_plan_price_id' => $plan_price->billing_plan_price_id,
                    'billing_plan_price' => $plan_price->id,
                    'product_type' => ApiProductTypeKey::SUBSCRIPTION->value
                ],
            ]
        ];

        try {
            $response = Paddle::api("POST", "products", $payload);

           return static::updateBillingPlanMetadata($plan_price, $data = $response->json());
        } catch (PaddleApiError $e) {
            throw new PaddleApiError($e->getMessage(), 400);
        }
    }

    /** 
     * @see https://developer.paddle.com/build/products/create-products-prices#request-subscription-plan-create-price 
     * @see https://developer.paddle.com/api-reference/prices/update-price
    */
    public static function updateSubscriptionProduct(BillingPlanPrice $plan_price, array $payload)
    {
        $plan_price->loadMissing([
            'billing_plan' => [
                'billing_product'
            ],
            'billing_plan_payment_provider_information' => fn ($query) => $query->where('payment_provider', PaymentProvider::Paddle)
        ]);

        if ($plan_price->billing_plan_payment_provider_information->first()->payment_provider_plan_id === null) return;

        $product_id = $plan_price->billing_plan_payment_provider_information->first()->payment_provider_plan_id;

        try {
            $response = Paddle::api("PATCH", "products/{$product_id}", $payload);

           return static::updateBillingPlanPriceMetadata($plan_price, $data = $response->json());
        } catch (PaddleApiError $e) {
            throw new PaddleApiError($e->getMessage(), 400);
        }
    }

    /**
     * @see https://developer.paddle.com/api-reference/prices/list-prices
     */
    public static function getSubscriptionProductPrices(array $query = [])
    {
        try {
            $response = Paddle::api("GET", "prices", [
                ...$query,
                'custom_data[metadata][product_type]' => ApiProductTypeKey::SUBSCRIPTION->value
            ]);

           return $response->json();
        } catch (PaddleApiError $e) {
            throw new PaddleApiError($e->getMessage(), 400);
        }
    }

    /**
     * @see https://developer.paddle.com/api-reference/prices/get-price
     */
    public static function getSubscriptionProductPrice(string $price_id)
    {
        try {
            $response = Paddle::api("GET", "prices/{$price_id}");

           return $response->json();
        } catch (PaddleApiError $e) {
            throw new PaddleApiError($e->getMessage(), 400);
        }
    }

    /** 
     * @see https://developer.paddle.com/build/products/create-products-prices#request-subscription-plan-create-price 
     * @see https://developer.paddle.com/api-reference/prices/create-price
    */
    public static function createSubscriptionProductPrice(BillingPlanPrice $plan_price)
    {
        $plan_price->loadMissing([
            'billing_plan' => [
                'billing_product'
            ],
            'billing_plan_payment_provider_information' => fn ($query) => $query->where('payment_provider', PaymentProvider::Paddle)
        ]);

        if ($plan_price->billing_plan_payment_provider_information->first()->payment_provider_plan_id === null) return;

        $supportTrials = $plan_price->billing_plan->trial_days > 0;
        $trialCount = $plan_price->billing_plan->trial_days;

        $payload = [
            'description' => $supportTrials 
                ? Str::title($plan_price->interval->value) . " price recurring with {$trialCount} day trial"
                : Str::title($plan_price->interval->value) . " price recurring"
            ,
            'name' => Str::title($plan_price->interval->value) . ' Price',
            'product_id' => $plan_price->billing_plan_payment_provider_information->first()->payment_provider_plan_id,
            'unit_price' => [
                'amount' => (string) $plan_price->amount,
                'currency_code' => $plan_price->currency,
            ],
            'billing_cycle' => [
                'interval' => match ($plan_price->interval) {
                    SubscriptionInterval::DAILY => 'day',
                    SubscriptionInterval::WEEKLY => 'week',
                    SubscriptionInterval::MONTHLY => 'month',
                    SubscriptionInterval::YEARLY => 'year',
                    default => 'month'
                },
                'frequency' => $plan_price->billing_interval_count ?? 1
            ],
            'trial_period' => $supportTrials ? [
                'interval' => 'day',
                'frequency' =>  $plan_price->billing_plan->trial_days
            ] : null,
            'tax_mode' => 'account_setting',
            'custom_data' => [
                'metadata' => [
                    'billing_plan' => $plan_price->billing_plan->id,
                    'billing_plan_id' => $plan_price->billing_plan->billing_plan_id,
                    'billing_product' => $plan_price->billing_plan->billing_product->id,
                    'billing_product_id' => $plan_price->billing_plan->billing_product->billing_product_id,
                    'billing_plan_price_id' => $plan_price->billing_plan_price_id,
                    'billing_plan_price' => $plan_price->id,
                    'product_type' => ApiProductTypeKey::SUBSCRIPTION->value
                ],
            ]
        ];

        try {
            $response = Paddle::api("POST", "prices", $payload);

           return static::updateBillingPlanPriceMetadata($plan_price, $data = $response->json());
        } catch (PaddleApiError $e) {
            throw new PaddleApiError($e->getMessage(), 400);
        }
    }

    /** 
     * @see https://developer.paddle.com/build/products/create-products-prices#request-subscription-plan-create-price 
     * @see https://developer.paddle.com/api-reference/prices/update-price
    */
    public static function updateSubscriptionProductPrice(BillingPlanPrice $plan_price, array $payload)
    {
        $plan_price->loadMissing([
            'billing_plan' => [
                'billing_product'
            ],
            //'billing_plan_payment_provider_information' => fn ($query) => $query->where('payment_provider', PaymentProvider::Paddle),
            'billing_plan_price_payment_provider_information' => fn ($query) => $query->where('payment_provider', PaymentProvider::Paddle),
        ]);

        if ($plan_price->billing_plan_price_payment_provider_information->first()->payment_provider_plan_id === null) return;

        $price_id = $plan_price->billing_plan_price_payment_provider_information->first()->payment_provider_plan_id;

        try {
            $response = Paddle::api("PATCH", "prices/{$price_id}", $payload);

           return static::updateBillingPlanPriceMetadata($plan_price, $data = $response->json());
        } catch (PaddleApiError $e) {
            throw new PaddleApiError($e->getMessage(), 400);
        }
    }


    public static function updateBillingPlanMetadata(BillingPlanPrice $plan_price, array $response)
    {
        $plan_price->loadMissing('billing_plan');

        return DB::transaction(fn () => $plan_price->billing_plan_payment_provider_information()->updateOrCreate([
            'payment_provider' => PaymentProvider::Paddle,
            'billing_plan_id' => $plan_price->billing_plan->id,
            'billing_plan_price_id' => $plan_price->id,
            'payment_provider_plan_id' => $response['data']['id'],
        ],[
            'is_active' => $response['data']['status'] === 'active',
            'status' => $response['data']['status'] === 'active' ? 'ACTIVE' : 'INACTIVE',
            'metadata' => [...$response['data'], 'response_meta' => $response['meta'] ?? null]
        ]));
    }

    public static function updateBillingPlanMetadataList(BillingPlanPrice $plan_price, array $item)
    {
        $plan_price->loadMissing('billing_plan');

        return DB::transaction(fn () => $plan_price->billing_plan_payment_provider_information()->updateOrCreate([
            'payment_provider' => PaymentProvider::Paddle,
            'billing_plan_id' => $plan_price->billing_plan->id,
            'billing_plan_price_id' => $plan_price->id,
            'payment_provider_plan_id' => $item['id'],
        ],[
            'is_active' => $item['status'] === 'active',
            'status' => $item['status'] === 'active' ? 'ACTIVE' : 'INACTIVE',
            'metadata' => $item
        ]));
    }

    public static function updateBillingPlanPriceMetadata(BillingPlanPrice $plan_price, array $response)
    {
        $plan_price->loadMissing('billing_plan');

        return DB::transaction(fn () => $plan_price->billing_plan_price_payment_provider_information()->updateOrCreate([
            'payment_provider' => PaymentProvider::Paddle,
            'billing_plan_id' => $plan_price->billing_plan->id,
            'billing_plan_price_id' => $plan_price->id,
            'payment_provider_plan_price_id' => $response['data']['id'],
            'payment_provider_plan_id' => $response['data']['product_id'],
        ],[
            'is_active' => $response['data']['status'] === 'active',
            'status' => $response['data']['status'] === 'active' ? 'ACTIVE' : 'INACTIVE',
            'metadata' => [...$response['data'], 'response_meta' => $response['meta']]
        ]));
    }

    public static function updateBillingPlanPriceMetadataList(BillingPlanPrice $plan_price, array $item)
    {
        $plan_price->loadMissing('billing_plan');

        return DB::transaction(fn () => $plan_price->billing_plan_price_payment_provider_information()->updateOrCreate([
            'payment_provider' => PaymentProvider::Paddle,
            'billing_plan_id' => $plan_price->billing_plan->id,
            'billing_plan_price_id' => $plan_price->id,
            'payment_provider_plan_price_id' => $item['id'],
            'payment_provider_plan_id' => $item['product_id'],
        ],[
            'is_active' => $item['status'] === 'active',
            'status' => $item['status'] === 'active' ? 'ACTIVE' : 'INACTIVE',
            'metadata' => $item
        ]));
    }


    public static function updateBillingProductMetadata(BillingProduct $product, array $productResponse, array $priceResponse)
    {
        DB::transaction(function () use ($product, $productResponse, $priceResponse) {
            
            $metadata = $product->metadata[PaymentProvider::Paddle->value] ?? [];

            $metadata = [...$metadata, ...$productResponse];

            if (isset($metadata['prices']) && is_array($metadata['prices'])) {
                array_push($metadata['prices'], $priceResponse);
            } else {
                $metadata['prices'][] = $priceResponse;
            }

            $product->update([
                'metadata' => array_merge(
                    $product->metadata ?? [],
                    [
                        PaymentProvider::Paddle->value => $metadata,
                    ]
                )
            ]); 

            $priceIds = [];

            foreach ($priceResponse as $pid) {
                $priceIds[] = is_array($pid) && isset($pid['id']) ? $pid['id'] : $pid;
            }

            $product->billing_product_payment_provider_information()->updateOrCreate([
                'payment_provider' => PaymentProvider::Paddle,
                'payment_provider_product_id' => $productResponse['id'],
                'payment_provider_price_id' => isset($priceResponse[0]) && is_array($priceResponse[0]) ? $priceResponse[0]['id'] : $priceResponse['id'],
            ],[
                'is_active' => true,
                'is_archived' => false,
                'payment_provider_media_id' => null,
                'payment_provider_price_ids' => $priceIds,
                'payment_provider_media_ids' => null,
                'metadata' => [...$productResponse, 'prices' => [...$priceResponse]]
            ]);
        });

        return $product;
    }
}
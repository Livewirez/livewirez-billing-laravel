<?php 

namespace Livewirez\Billing\Lib\Polar;

use Illuminate\Http\Client\Pool;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Date;
use Livewirez\Billing\Enums\PaymentProvider;
use Livewirez\Billing\Models\BillingProduct;
use Livewirez\Billing\Enums\ApiProductTypeKey;
use Livewirez\Billing\Models\BillingPlanPrice;
use Livewirez\Billing\Enums\SubscriptionInterval;
use Livewirez\Billing\Models\BillingDiscountCode;
use Livewirez\Billing\Lib\Polar\Exceptions\PolarApiError;
use Livewirez\Billing\Lib\Polar\Data\Discounts\DiscountData;
use Livewirez\Billing\Models\BillingPlanPaymentProviderInformation;
use Livewirez\Billing\Lib\Polar\Data\Discounts\CheckoutDiscountFixedRepeatDurationData;
use Livewirez\Billing\Lib\Polar\Data\Discounts\CheckoutDiscountFixedOnceForeverDurationData;
use Livewirez\Billing\Lib\Polar\Data\Discounts\CheckoutDiscountPercentageRepeatDurationData;
use Livewirez\Billing\Lib\Polar\Data\Discounts\CheckoutDiscountPercentageOnceForeverDurationData;


class DiscountsManager
{
    public static function createDiscount(BillingProduct $billingProduct, ?callable $pathResolver = null)
    {

    }

    /**
     * @see https://docs.polar.sh/api-reference/discounts/get
     * @throws \Livewirez\Billing\Lib\Polar\Exceptions\PolarApiError
     * @return DiscountData|array
     */
    public static function getSubscriptionDiscount(string $polarId, bool $as_array = false): DiscountData|array
    {
        try {
            $response = Polar::api("GET", "v1/discounts/{$polarId}", [
                'metadata[product_type]' => ApiProductTypeKey::SUBSCRIPTION->value
            ]);

            return $as_array ? $response->json() : static::resolveDiscountDataFromResponse(
                $response->json(), 
                $response->json('type'), 
                $response->json('duration')
            );     
        } catch (PolarApiError $e) {
            throw new PolarApiError($e->getMessage(), 400);
        }
    }

    /**
     * @see https://docs.polar.sh/api-reference/discounts/list
     * @throws \Livewirez\Billing\Lib\Polar\Exceptions\PolarApiError
     * @return DiscountData[]
     */
    public static function getSubscriptionDiscounts(): array
    {
        try {
            $response = Polar::api("GET", "v1/discounts", [
                'metadata[product_type]' => ApiProductTypeKey::SUBSCRIPTION->value
            ]);

            $items = array_filter(
                $response->json('items', []),
                fn  (array $item): bool  =>
                isset($item['metadata'], $item['metadata']['billing_plan_ids'], $item['metadata']['billing_plan_price_ids'], $item['metadata']['billing_discount_code_id'])
            );

            return array_map(
                fn (array $item): DiscountData =>
                    static::resolveDiscountDataFromResponse($item, $item['type'], $item['duration']),
                $items
            );       
        } catch (PolarApiError $e) {
            throw new PolarApiError($e->getMessage(), 400);
        }
    }

    /** @see https://docs.polar.sh/api-reference/discounts/create */
    public static function createSubscriptionProductDiscount(BillingDiscountCode $discount_code)
    {
        if (! $payload = static::getSubscriptionPayload($discount_code)) return;

        try {
            $response = Polar::api("POST", "v1/discounts", $payload);

            $data = static::resolveDiscountDataFromResponse(
                $response->json(),
                $response->json('type'),
                $response->json('duration')
            );
            
            return $data->setModel(
                static::updateBillingDiscountCodeMetadata($discount_code, $data)
            );
        } catch (PolarApiError $e) {
            throw new PolarApiError($e->getMessage(), 400);
        }
    }

    /**
     * Summary of createSubscriptionProductDiscountCollection
     * @param \Illuminate\Support\Collection<BillingDiscountCode> $collection
     * @return array
     */
    public static function createSubscriptionProductDiscountCollection(Collection $collection)
    {
        $collection->filter(static::dicountCodeHasNeededData(...));

        [$planPriceHasNeededData, $planPriceDoesNotHaveNeededData] = $collection->partition(static::dicountCodeHasNeededData(...));

        if ($planPriceHasNeededData->isEmpty()) return [];

        $planPriceHasNeededData = $planPriceHasNeededData->values();

        $callback = fn (Pool $pool, string $url, string $token) => 
            $planPriceHasNeededData->map(static::getSubscriptionPayload(...))
            ->map(fn (array $payload) => $pool->baseUrl($url)->withToken($token)
                ->asJson()
                ->post("/v1/discounts", $payload)
            )->toArray();

        $responses = Polar::apiPool($callback);

        return array_map(function (mixed $response, int $key) use ($planPriceHasNeededData) {

            if ($response instanceof Response && $response->successful()) {
            
                $data = static::resolveDiscountDataFromResponse(
                    $response->json(),
                    $response->json('type'),
                    $response->json('duration')
                );
                
                return $data->setModel(
                    static::updateBillingDiscountCodeMetadata($planPriceHasNeededData[$key], $data)
                );
            }

            return $response;
        }, $responses, array_keys($planPriceHasNeededData->toArray()));
    }


    public static function updateSubscriptionProductDiscount(string $polarId, BillingDiscountCode $discount_code, array $updates)
    {
        if (! $payload = static::getSubscriptionPayload($discount_code)) return;

        $payload = array_merge($payload, $updates);

        try {
            $response = Polar::api("PATCH", "v1/discounts/{$polarId}", $payload);

            $data = static::resolveDiscountDataFromResponse(
                $response->json(),
                $response->json('type'),
                $response->json('duration')
            );
            
            return $data->setModel(
                static::updateBillingDiscountCodeMetadata($discount_code, $data)
            );
        } catch (PolarApiError $e) {
            throw new PolarApiError($e->getMessage(), 400);
        }
    }

    public static function deleteSubscriptionProductDiscount(string $polarId): bool 
    {
        try {
            $response = Polar::api("DELETE", "v1/discounts/{$polarId}");

            return $response->successful();
        } catch (PolarApiError $e) {
            throw new PolarApiError($e->getMessage(), 400);
        }
    }

    public static function resolveDiscountDataFromResponse(array $data, string $type, string $duration): DiscountData
    {
        return match ($type) {
            'percentage' => match ($duration) {
                'once', 'forever' => CheckoutDiscountPercentageOnceForeverDurationData::from($data),
                'repeating' => CheckoutDiscountPercentageRepeatDurationData::from($data)
            },
            'fixed', 'fixed_amount' => match ($duration) {
                'once', 'forever' => CheckoutDiscountFixedOnceForeverDurationData::from($data),
                'repeating' => CheckoutDiscountFixedRepeatDurationData::from($data)
            },
        };
    }
    
    public static function updateBillingDiscountCodeMetadata(BillingDiscountCode $discount_code, DiscountData $response)
    {
        $discount_code->loadMissing([
            'billing_plan_prices' => [
                'billing_plan' => [
                    'billing_product'
                ],
            ],
            'billing_plan_payment_provider_information' => fn ($query) => $query->where('payment_provider', PaymentProvider::Polar),
        ]);

        return DB::transaction(function () use ($discount_code, $response) {

            $plan_price_ids = json_decode($response->metadata['billing_plan_price_ids'], true, JSON_THROW_ON_ERROR);

            $metadata = is_string($discount_code->metadata) 
                ?  json_decode($discount_code->metadata ?? '[]', true) 
                : ($discount_code->metadata ?? []);

            $discount_code->update([
                'metadata' => array_merge($metadata, [
                    PaymentProvider::Polar->value => $response->toArray(),
                ])
            ]);

            foreach ($plan_price_ids as $pid) {
                $discount_code?->billing_discount_code_payment_provider_information()
                ->updateOrCreate([
                        'payment_provider' => PaymentProvider::Polar,
                        'payment_provider_discount_code_id' => $response->id,
                        'billing_plan_price_id' => $pid,
                    ],
                    [
                        'code' => $response->code,
                        'type' => $response->type,
                        'metadata' => $response->toArray()
                    ]
                );
            }

            return $discount_code;
        });
    }

    public static function dicountCodeHasNeededData(BillingDiscountCode $discount_code): bool
    {
        $discount_code->loadMissing([
            'billing_plan_prices' => [
                'billing_plan' => [
                    'billing_product'
                ],
            ],
            'billing_plan_payment_provider_information' => fn ($query) => $query->where('payment_provider', PaymentProvider::Polar),
        ]);

        return $discount_code->billing_type === ApiProductTypeKey::SUBSCRIPTION->value 
                && $discount_code->billing_plan_payment_provider_information->isNotEmpty();
    }

    public static function getSubscriptionPayload(BillingDiscountCode $discount_code)
    {
        if (! static::dicountCodeHasNeededData($discount_code)) return; 

        return [
            'duration' => $duration = match (true) { // once, forever, repeating
                $discount_code->ends_at === null || $discount_code->max_uses === null => 'forever',
                $discount_code->ends_at !== null => 'repeating',
                $discount_code->max_uses === 1 => 'once',
                default => 'once'
            },
            'type' => $type = match ($discount_code->type) {
                'percentage' => 'percentage', 
                'fixed_amount', 'fixed' => 'fixed'
            }, 
            'currency' => $discount_code->currency,
            'name' => $discount_code->name,
            'code' => $discount_code->code,
            'starts_at' => $discount_code->starts_at,
            'ends_at' => $discount_code->expires_at,
            'max_redemptions' => $discount_code->max_uses,
            // 'organization_id' => '', //config('billing.providers.polar.organization_id')
            'products' => $discount_code->billing_plan_payment_provider_information->map(
                fn (BillingPlanPaymentProviderInformation $ppi) => $ppi->payment_provider_plan_id
            )->toArray(),
            'metadata' => [
                'billing_plans' => json_encode($discount_code->billing_plan_prices->map(fn (BillingPlanPrice $pp) => $pp->billing_plan->id)
                ->unique()->toArray()),
                'billing_plan_ids' => json_encode($discount_code->billing_plan_prices->map(fn (BillingPlanPrice $pp) => $pp->billing_plan->billing_plan_id)
                ->unique()->toArray()),
                'billing_product_ids' => json_encode($discount_code->billing_plan_prices->map(fn (BillingPlanPrice $pp) => $pp->billing_plan->billing_product->billing_product_id)
                ->unique()->toArray()),
                'billing_products' => json_encode($discount_code->billing_plan_prices->map(fn (BillingPlanPrice $pp) => $pp->billing_plan->billing_product->id)
                ->unique()->toArray()),
                'billing_plan_price_ids' => json_encode($discount_code->billing_plan_prices->map(fn (BillingPlanPrice $pp) => $pp->id)
                ->unique()->toArray()),
                'billing_discount_code_id' => $discount_code->billing_discount_code_id,
                'billing_discount_code' => $discount_code->id,
                'product_type' => ApiProductTypeKey::SUBSCRIPTION->value
            ],
            ...$type === 'percentage' ? [
                'basis_points' => $discount_code->value * 100
            ] : [],
            ...in_array($type, ['fixed', 'fixed_amount']) ? [
                'amount' => $discount_code->value
            ] : [],
            ...$duration === 'repeating' ? [
                'duration_in_months' => count($discount_code->plan_prices) > 0
                    ? array_sum(array_map(
                        fn ($plan_price) => match ($plan_price->interval) {
                            SubscriptionInterval::MONTHLY => (int) now()->diffInMonths(Date::parse($discount_code->ends_at)),
                            SubscriptionInterval::YEARLY => (int) now()->diffInYears(Date::parse($discount_code->ends_at)) * 12,
                            default => 1,
                        },
                        $discount_code->plan_prices
                    )) / count($discount_code->plan_prices)
                    : 0
            ] : []
        ];
    }
}
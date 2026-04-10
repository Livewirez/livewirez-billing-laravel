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


class DiscountsManager__
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
                'metadata[product_type]' => 'subscription'
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
                'metadata[product_type]' => 'subscription'
            ]);

            $items = array_filter(
                $response->json('items') ?? [],
                fn  (array $item): bool  =>
                isset($item['metadata']) && isset($item['metadata']['billing_plan_id']) && isset($item['metadata']['billing_discount_code_id'])
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
    public static function createSubscriptionProductDiscount(BillingPlanPrice $plan_price)
    {
        if (! $payload = static::getSubscriptionPayload($plan_price)) return;

        try {
            $response = Polar::api("POST", "v1/discounts", $payload);

            $data = static::resolveDiscountDataFromResponse(
                $response->json(),
                $response->json('type'),
                $response->json('duration')
            );
            
            return $data->setModel(
                static::updateBillingDiscountCodeMetadata($plan_price, $data)
            );
        } catch (PolarApiError $e) {
            throw new PolarApiError($e->getMessage(), 400);
        }
    }

    public static function updateSubscriptionProductDiscount(string $polarId, BillingPlanPrice $plan_price, array $updates)
    {
        if (! $payload = static::getSubscriptionPayload($plan_price)) return;

        $payload = array_merge($payload, $updates);

        try {
            $response = Polar::api("PATCH", "v1/discounts/{$polarId}", $payload);

            $data = static::resolveDiscountDataFromResponse(
                $response->json(),
                $response->json('type'),
                $response->json('duration')
            );
            
            return $data->setModel(
                static::updateBillingDiscountCodeMetadata($plan_price, $data)
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

    /**
     * Summary of createSubscriptionProductDiscountCollection
     * @param \Illuminate\Support\Collection<BillingPlanPrice> $collection
     * @return array
     */
    public static function createSubscriptionProductDiscountCollection(Collection $collection)
    {
        $collection->filter(static::planPriceHasNeededData(...));

        [$planPriceHasNeededData, $planPriceDoesNotHaveNeededData] = $collection->partition(static::planPriceHasNeededData(...));

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
    
    public static function updateBillingDiscountCodeMetadata(BillingPlanPrice $plan_price, DiscountData $response)
    {
        $plan_price->loadMissing([
            'billing_plan' => [
                'billing_product'
            ],
            'billing_plan_payment_provider_information' => fn ($query) => $query->where('payment_provider', PaymentProvider::Polar),
            'billing_discount_code' => fn ($query) => $query->where([
                'is_active' => true,
                'code' => $response->code,
                'billing_type' => ApiProductTypeKey::SUBSCRIPTION->value 
            ])
        ]);

        return DB::transaction(function () use ($plan_price, $response) {

            $plan_price->billing_discount_code?->update([
                'metadata' => array_merge($plan_price->billing_discount_code->metadata ?? [], [
                    PaymentProvider::Polar->value => $response->toArray(),
                ])
            ]);

            return $plan_price->billing_discount_code?->billing_discount_code_payment_provider_information()
            ->updateOrCreate([
                    'payment_provider' => PaymentProvider::Polar,
                    'payment_provider_discount_code_id' => $response->id,
                    'billing_plan_price_id' => $plan_price->id,
                ],
                [
                    'code' => $response->code,
                    'type' => $response->type,
                    'metadata' => $response->toArray()
                ]
            );
        });
    }

    public static function planPriceHasNeededData(BillingPlanPrice $plan_price): bool
    {
        $plan_price->loadMissing([
            'billing_plan' => [
                'billing_product'
            ],
            'billing_plan_payment_provider_information' => fn ($query) => $query->where('payment_provider', PaymentProvider::Polar),
            'billing_discount_code' => fn ($query) => $query->where([
                'is_active' => true,
                'billing_type' => ApiProductTypeKey::SUBSCRIPTION->value 
            ])
        ]);

        return $plan_price->billing_plan_payment_provider_information->isNotEmpty() 
                && $plan_price->billing_discount_code !== null;
    }

    public static function getSubscriptionPayload(BillingPlanPrice $plan_price)
    {
        if (! static::planPriceHasNeededData($plan_price)) return; 

        $discount_code = $plan_price->billing_discount_code; 

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
            'products' => $plan_price->billing_plan_payment_provider_information->map(
                fn (BillingPlanPaymentProviderInformation $ppi) => $ppi->payment_provider_plan_id
            )->toArray(),
            'metadata' => [
                'billing_plan_id' => $plan_price->billing_plan->billing_plan_id,
                'billing_plan' => $plan_price->billing_plan->id,
                'billing_product_id' => $plan_price->billing_plan->billing_product->billing_product_id,
                'billing_product' => $plan_price->billing_plan->billing_product->id,
                'billing_plan_price_id' => $plan_price->id,
                'billing_discount_code_id' => $plan_price->billing_discount_code->billing_discount_code_id,
                'billing_discount_code' => $plan_price->billing_discount_code->id,
                'product_type' => ApiProductTypeKey::SUBSCRIPTION->value
            ],
            ...$type === 'percentage' ? [
                'basis_points' => $discount_code->value * 100
            ] : [],
            ...in_array($type, ['fixed', 'fixed_amount']) ? [
                'amount' => $discount_code->value
            ] : [],
            ...$duration === 'repeating' ? [
                'duration_in_months' => fn ($plan_price) => match ($plan_price->interval) {
                    SubscriptionInterval::MONTHLY => (int) now()->diffInMonths(Date::parse($discount_code->ends_at)),
                    SubscriptionInterval::YEARLY => (int) now()->diffInYears(Date::parse($discount_code->ends_at)) * 12,
                    default => 1,
                },
            ] : []
        ];
    }
}
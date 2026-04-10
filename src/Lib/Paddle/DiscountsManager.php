<?php 

namespace Livewirez\Billing\Lib\Paddle;

use Exception;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Date;
use Livewirez\Billing\Enums\ProductType;
use Livewirez\Billing\Enums\PaymentProvider;
use Livewirez\Billing\Models\BillingProduct;
use Livewirez\Billing\Enums\ApiProductTypeKey;
use Livewirez\Billing\Models\BillingPlanPrice;
use Livewirez\Billing\Enums\SubscriptionInterval;
use Livewirez\Billing\Models\BillingDiscountCode;
use Livewirez\Billing\Lib\Paddle\Exceptions\PaddleApiError;
use Livewirez\Billing\Models\BillingPlanPaymentProviderInformation;
use Livewirez\Billing\Models\BillingPlanPricePaymentProviderInformation;

class DiscountsManager
{
    public static function dicountCodeHasNeededData(BillingDiscountCode $discount_code): bool
    {
        $discount_code->loadMissing([
            'billing_plan_prices' => [
                'billing_plan' => [
                    'billing_product'
                ],
                'billing_plan_price_payment_provider_information' => fn ($query) => $query->where('payment_provider', PaymentProvider::Paddle),
            ],
            'billing_plan_payment_provider_information' => fn ($query) => $query->where('payment_provider', PaymentProvider::Paddle),
        ]);

        return $discount_code->billing_type === ApiProductTypeKey::SUBSCRIPTION->value 
                && $discount_code->billing_plan_payment_provider_information->isNotEmpty();
    }

    public static function getDiscounts(array $query = []): array
    {
        try {
            $response = Paddle::api("GET", "discounts", [
                ...$query,
            ]);

            return $response->json('data');
        } catch (PaddleApiError $e) {
            throw new PaddleApiError($e->getMessage(), 400);
        }
    }

    public static function getSubscriptionDiscount(string $paddleId): array
    {
        try {
            $response = Paddle::api("GET", "discounts/{$paddleId}");

            return $response->json('data');     
        } catch (PaddleApiError $e) {
            throw new PaddleApiError($e->getMessage(), 400);
        }
    }

     /** @see https://docs.polar.sh/api-reference/discounts/create */
    public static function createSubscriptionProductDiscount(BillingDiscountCode $discount_code)
    {
        if (! $payload = static::getSubscriptionPayload($discount_code)) return;

        try {
            $response = Paddle::api("POST", "discounts", $payload);

            $data = $response->json('data');

            static::updateBillingDiscountCodeMetadata($discount_code, $data ?? []);
            
            return $data;
        } catch (PaddleApiError $e) {
            throw new PaddleApiError($e->getMessage(), 400);
        }
    }

    public static function updateSubscriptionProductDiscount(string $paddleId, BillingDiscountCode $discount_code, array $updates)
    {
        if (! $payload = static::getSubscriptionPayload($discount_code)) return;

        $payload = array_merge($payload, $updates, [
            'status' => $discount_code->is_active ? 'active' : 'archived'
        ]);

        try {
            $response = Paddle::api("PATCH", "discounts/{$paddleId}", $payload);

            $data = $response->json('data');

            static::updateBillingDiscountCodeMetadata($discount_code, $data ?? []);
            
            return $data;
        } catch (PaddleApiError $e) {
            throw new PaddleApiError($e->getMessage(), 400);
        }
    }

    public static function deleteSubscriptionProductDiscount(string $paddleId): bool 
    {
        try {
            $response = Paddle::api("PATCH", "discounts/{$paddleId}", ['status' => 'archived']);

            return $response->successful();
        } catch (PaddleApiError $e) {
            throw new PaddleApiError($e->getMessage(), 400);
        }
    }

    // $start->format(DATE_RFC3339)
    public static function getSubscriptionPayload(BillingDiscountCode $discount_code)
    {
        if (! static::dicountCodeHasNeededData($discount_code)) return; 

        if (! in_array($discount_code->currency, static::allowedCurrencies())) return;

        $infos = $discount_code->billing_plan_prices->flatMap(fn (BillingPlanPrice $price) => $price->billing_plan->billing_plan_price_payment_provider_information)
            ->unique('id')->values();

        $paddle_product_price_ids = $infos->map(fn (BillingPlanPricePaymentProviderInformation $i) => $i->payment_provider_plan_id)
            ->merge($infos->map(fn (BillingPlanPricePaymentProviderInformation $i) => $i->payment_provider_plan_price_id))->all();

        return [
            'amount' => (string) $discount_code->value,
            'restrict_to' => count($paddle_product_price_ids) > 0 ? $paddle_product_price_ids : null,
            'usage_limit' => $discount_code->max_uses,
            'recur' => false,
            'enabled_for_checkout' => true,
            'type' => $type = match ($discount_code->type) {
                'percentage' => 'percentage', 
                'fixed_amount', 'fixed' => 'flat',
                default => throw new \DomainException("Unsupported discount type: {$discount_code->type}")
            }, 
            'currency_code' => $discount_code->currency,
            'description' => $discount_code->name,
            'code' => $discount_code->code,
            'expires_at' => $discount_code->expires_at?->format(DATE_RFC3339),
            'custom_data' => [
                'metadata' => [
                    'billing_plans' => $discount_code->billing_plan_prices->map(fn (BillingPlanPrice $pp) => $pp->billing_plan->id)
                    ->unique()->toArray(),
                    'billing_plan_ids' => $discount_code->billing_plan_prices->map(fn (BillingPlanPrice $pp) => $pp->billing_plan->billing_plan_id)
                    ->unique()->toArray(),
                    'billing_product_ids' => $discount_code->billing_plan_prices->map(fn (BillingPlanPrice $pp) => $pp->billing_plan->billing_product->billing_product_id)
                    ->unique()->toArray(),
                    'billing_products' => $discount_code->billing_plan_prices->map(fn (BillingPlanPrice $pp) => $pp->billing_plan->billing_product->id)
                    ->unique()->toArray(),
                    'billing_plan_price_ids' => $discount_code->billing_plan_prices->map(fn (BillingPlanPrice $pp) => $pp->id)
                    ->unique()->toArray(),
                    'billing_discount_code_id' => $discount_code->billing_discount_code_id,
                    'billing_discount_code' => $discount_code->id,
                    'product_type' => ApiProductTypeKey::SUBSCRIPTION->value
                ],
            ],
        ];
    }

    public static function updateBillingDiscountCodeMetadata(BillingDiscountCode $discount_code, array $data)
    {
        $discount_code->loadMissing([
            'billing_plan_prices' => [
                'billing_plan' => [
                    'billing_product'
                ],
            ],
            'billing_plan_payment_provider_information' => fn ($query) => $query->where('payment_provider', PaymentProvider::Paddle),
        ]);

        return DB::transaction(function () use ($discount_code, $data) {

            if (! isset(
                $data['custom_data'],
                $data['custom_data']['metadata'],
                $data['custom_data']['metadata']['billing_plan_price_ids'],
            )) return;

            $plan_price_ids = $data['custom_data']['metadata']['billing_plan_price_ids'];

            $metadata = is_string($discount_code->metadata) 
                ?  json_decode($discount_code->metadata ?? '[]', true) 
                : ($discount_code->metadata ?? []);

            $discount_code->update([
                'metadata' => array_merge($metadata, [
                    PaymentProvider::Paddle->value => $data,
                ])
            ]);

            foreach ($plan_price_ids as $pid) {
                $discount_code?->billing_discount_code_payment_provider_information()
                ->updateOrCreate([
                        'payment_provider' => PaymentProvider::Paddle,
                        'payment_provider_discount_code_id' => $data['id'],
                        'billing_plan_price_id' => $pid,
                    ],
                    [
                        'code' => $data['code'],
                        'type' => $data['type'],
                        'metadata' => $data
                    ]
                );
            }

            return $discount_code;
        });
    }

    private static function allowedCurrencies()
    {
        return [
            'USD', // United States Dollar
            'EUR', // Euro
            'GBP', // Pound Sterling
            'JPY', // Japanese Yen
            'AUD', // Australian Dollar
            'CAD', // Canadian Dollar
            'CHF', // Swiss Franc
            'HKD', // Hong Kong Dollar
            'SGD', // Singapore Dollar
            'SEK', // Swedish Krona
            'ARS', // Argentine Peso
            'BRL', // Brazilian Real
            'CNY', // Chinese Yuan
            'COP', // Colombian Peso
            'CZK', // Czech Koruna
            'DKK', // Danish Krone
            'HUF', // Hungarian Forint
            'ILS', // Israeli Shekel
            'INR', // Indian Rupee
            'KRW', // South Korean Won
            'MXN', // Mexican Peso
            'NOK', // Norwegian Krone
            'NZD', // New Zealand Dollar
            'PLN', // Polish Zloty
            'RUB', // Russian Ruble
            'THB', // Thai Baht
            'TRY', // Turkish Lira
            'TWD', // New Taiwan Dollar
            'UAH',// Ukrainian Hryvnia
            'VND', // Vietnamese Dong
            'ZAR', // South African Rand
        ];
    }

}
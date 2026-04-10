<?php 

namespace Livewirez\Billing\Lib\PayPal;

use Livewirez\Billing\Money;
use Livewirez\Billing\Models\BillingPlan;
use Livewirez\Billing\Models\BillingProduct;
use Livewirez\Billing\Models\BillingPlanPrice;
use Livewirez\Billing\Enums\CurrencyType;
use Livewirez\Billing\Enums\SubscriptionInterval;
use Livewirez\Billing\Interfaces\ProductInterface;

class SubscriptionUtils
{
    /**
     * @source https://developer.paypal.com/studio/checkout/standard/integrate/recurring
     * 
     * @param \Livewirez\Billing\Models\BillingPlan $plan
     * @param \Livewirez\Billing\Interfaces\ProductInterface $product
     * @return array{billing_cycles: array, name: string, one_time_charges: array, product: array{description: mixed, quantity: int}}
     */
    public static function buildBillingPlan(BillingPlan $plan, ProductInterface $product)
    {
        return [
            'billing_cycles' => static::buildBillingPlansForTokens($plan, $product),
            'one_time_charges' => [
                'product_price' => [
                    'value' => Money::formatAmountUsingCurrency(100, $product->getCurrencyCode()),
                    'currency_code' => $product->getCurrencyCode()
                ],
                'total_amount' => [
                    'value' => Money::formatAmountUsingCurrency(100, $product->getCurrencyCode()),
                    'currency_code' => $product->getCurrencyCode()
                ]
            ],
            'product' => [
                'description' => $plan->description ?? 'No description provided',
                'quantity' => 1
            ],
            'name' => $plan->name
        ];
    }

    // public static function buildBillingPlansForTokens(Plan $plan, ProductInterface $product): array
    // {
    //     $billingCycles = [];

    //     // Load plan prices
    //     $prices = $plan->billing_prices()->where([
    //         'currency' => $product->getCurrencyCode(),
    //     ])->get();

    //     $sequence = 1;

    //     // Add a single regular billing cycle
    //     $regularInterval = SubscriptionInterval::MONTHLY; // Default to MONTHLY, can be configurable
    //     $priceRecord = $prices->where('interval', $regularInterval)->first();

    //     if (!$priceRecord && $prices->where('interval', SubscriptionInterval::YEARLY)->first()) {
    //         $regularInterval = SubscriptionInterval::YEARLY; // Fallback to YEARLY if no MONTHLY
    //         $priceRecord = $prices->where('interval', $regularInterval)->first();
    //     }

    //     if ($priceRecord) {
    //         $billingCycles[] = [
    //             'frequency' => [
    //                 'interval_unit' => $regularInterval === SubscriptionInterval::YEARLY ? 'YEAR' : 'MONTH',
    //                 'interval_count' => 1,
    //             ],
    //             'tenure_type' => 'REGULAR',
    //             'sequence' => $sequence++,
    //             'total_cycles' => 0, // Unlimited cycles
    //             'start_date' => now()->format('Y-m-d'),
    //             'pricing_scheme' => [
    //                 'pricing_model' => 'FIXED',
    //                 'price' => [
    //                     'value' => Money::formatAmountUsingCurrency($priceRecord->amount, $priceRecord->currency),
    //                     'currency_code' => $priceRecord->currency,
    //                 ],
    //             ],
    //         ];
    //     }

    //     return $billingCycles;
    // }


    /**
     * Summary of buildBillingPlansForTokens
     * @param \Livewirez\Billing\Models\BillingPlan $plan
     * @param \Livewirez\Billing\Interfaces\ProductInterface $product
     * @return array
     */
    public static function buildBillingPlansForTokens(BillingPlan $plan, ProductInterface $product): array
    {
        // Load plan prices
        $prices = $plan->billing_prices()->where([
            'currency' => $product->getCurrencyCode(),
        ])->get();

        return $prices->map(fn (BillingPlanPrice $p ,int $key) => [
            'frequency' => [
                'interval_unit' => $p->interval === SubscriptionInterval::YEARLY ? 'YEAR' : 'MONTH',
                'interval_count' => 1,
            ],
            'tenure_type' => 'REGULAR',
            'sequence' => ++$key,
            'total_cycles' => 0, // Unlimited cycles
            'start_date' => now()->format('Y-m-d'),
            'pricing_scheme' => [
                'pricing_model' => 'FIXED',
                'price' => [
                    'value' => Money::formatAmountUsingCurrency($p->amount, $p->currency),
                    'currency_code' => $p->currency,
                ],
            ],
        ])->take(3)->toArray();
    }
}
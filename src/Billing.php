<?php

namespace Livewirez\Billing;

use Illuminate\Support\Str;

class Billing
{

    public static string $billingCart = 'Livewirez\\Billing\\Models\\BillingCart';
    public static string $billingCartItem = 'Livewirez\\Billing\\Models\\BillingCartItem';

    public static string $billingCurrencyConversionRate = 'Livewirez\\Billing\\Models\\BillingCurrencyConversionRate';
    public static string $billableAddress = 'Livewirez\\Billing\\Models\\BillableAddress';
    public static string $billablePaymentMethod = 'Livewirez\\Billing\\Models\\BillablePaymentMethod';
    public static string $billablePaymentProviderInformation = 'Livewirez\\Billing\\Models\\BillablePaymentProviderInformation';

    public static string $billingDiscountCodePaymentProviderInformation = 'Livewirez\\Billing\\Models\\BillingDiscountCodePaymentProviderInformation';

    public static string $billingDiscountCode = 'Livewirez\\Billing\\Models\\BillingDiscountCode';
    public static string $billingOrder = 'Livewirez\\Billing\\Models\\BillingOrder';
    public static string $billingOrderItem = 'Livewirez\\Billing\\Models\\BillingOrderItem';
    public static string $billingOrderBillingProduct = 'Livewirez\\Billing\\Models\\BillingOrderBillingProduct';

    public static string $billingOrderShippingAddress = 'Livewirez\\Billing\\Models\\BillingOrderShippingAddress';
    public static string $billingPaymentTransaction = 'Livewirez\\Billing\\Models\\BillingPaymentTransaction';
    public static string $billingPlan = 'Livewirez\\Billing\\Models\\BillingPlan';
    public static string $billingPlanPrice = 'Livewirez\\Billing\\Models\\BillingPlanPrice';
    public static string $billingPlanPaymentProviderInformation = 'Livewirez\\Billing\\Models\\BillingPlanPaymentProviderInformation';  

    public static string $billingPlanPricePaymentProviderInformation = 'Livewirez\\Billing\\Models\\BillingPlanPricePaymentProviderInformation';
    public static string $billingProduct = 'Livewirez\\Billing\\Models\\BillingProduct';
    public static string $billingSubscription = 'Livewirez\\Billing\\Models\\BillingSubscription';
    public static string $billingSubscriptionTransaction = 'Livewirez\\Billing\\Models\\BillingSubscriptionTransaction';
    public static string $billingSubscriptionEvent = 'Livewirez\\Billing\\Models\\BillingSubscriptionEvent';
    public static string $billingSubscriptionDiscount = 'Livewirez\\Billing\\Models\\BillingSubscriptionDiscount';
    public static string $billingTransactionData = 'Livewirez\\Billing\\Models\\BillingTransactionData';
    public static string $billingProductPaymentProviderInformation = 'Livewirez\\Billing\\Models\\BillingProductPaymentProviderInformation';


    public static function makeUniqueId(int $length = 16): string
    {
        //return strtoupper(rand(1, 99999). time().uniqid());
        return strtoupper(bin2hex(random_bytes($length / 2)));
    }

    /**
     * Random Reference Generator used to generate unique IDs
     * @param mixed $prefix
     * @param mixed $length
     * @return string
     */
    public static function randomReference(string $prefix = 'BILLING', int $length = 10)
    {
        $keyspace = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $str = '';
        $max = mb_strlen($keyspace, '8bit') - 1;
        //$prefix ??= 'BILLING';
        // Generate a random string of the desired length
        for ($i = 0; $i < $length; ++$i) {
            $str .= $keyspace[random_int(0, $max)];
        }

        // Append the current timestamp in milliseconds for uniqueness
        $timestamp = round(microtime(true) * 1000);

        return $prefix . '-' . $timestamp . '-' . $str;
    }
}
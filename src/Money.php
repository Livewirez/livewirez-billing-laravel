<?php

namespace Livewirez\Billing;

use App\Models\BillingCurrencyConversionRate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

class Money
{
    public static function formatAmount(int $amount, int $scale): float
    {
        return (float) bcdiv((string) $amount, bcpow('10', (string) $scale), $scale);
    }

    public static function formatAmountUsingCurrency(int $amount, string $currency): float 
    {
        $scale = match (strtoupper($currency)) {
            'BTC' => 8,
            'ETH' => 18,
            'USDT' => 6,
            'USD', 'EUR', 'KES', 'KSH' => 2,
            default => 2,
        };

        return static::formatAmount($amount, $scale);
    }

    public static function formatMajorAmount(float $amount, int $decimals = 2): float
    {
        return (float) number_format($amount, $decimals, '.', '');
    }

    public static function convertCurrency(int|float|string $amount, string $fromCurrency, string $toCurrency): object
    {
        $amount = (float) $amount; // normalize

        if ($exchangeRate = Redis::get("{$fromCurrency}-{$toCurrency}")) {

            $convertedAmount = $amount * $exchangeRate;

            return new ConvertedCurrency($convertedAmount, $toCurrency, $exchangeRate);
        }



        if ($currencyRate = BillingCurrencyConversionRate::where([
            'base_code' => $fromCurrency,
            'target_code' => $toCurrency
        ])->first()) {
            $exchangeRate = $currencyRate->rate;
            $convertedAmount = $currencyRate->convertCurrency($amount);

            return new ConvertedCurrency($convertedAmount, $toCurrency, $exchangeRate);
        }
        
        $data = Http::get("https://api.exchangerate-api.com/v4/latest/{$fromCurrency}")->json();
        $rates = $data['rates'];

        if ($toCurrency === 'KSH') {
            $toCurrency = 'KES';
        }

        $exchangeRate = $rates[$toCurrency] ?? 1;
        $convertedAmount = $amount * $exchangeRate;

        return new ConvertedCurrency($convertedAmount, $toCurrency, $exchangeRate);
    }
}

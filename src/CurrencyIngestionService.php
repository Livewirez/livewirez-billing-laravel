<?php

namespace Livewirez\Billing;

use App\Models\BillingCurrencyConversionRate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\ConnectionException;
use Exception;

class CurrencyIngestionService
{
    protected ?string $baseUrl = null;
    /**
     * Fetches and ingests currency rates from the active API.
     */
    public function ingestCurrencyRates(string $activeCurrencyApi, string $defaultCurrency): void
    {
        $baseUrl = config("billing.currencies.{$activeCurrencyApi}.base_url");
        $apiKey = config("billing.currencies.{$activeCurrencyApi}.api_key");

        // Create the pending request instance with common settings
        $pendingRequest = Http::baseUrl($baseUrl)
            ->asJson()
            ->retry(3, 100, fn(Exception $exception, PendingRequest $request) => $exception instanceof ConnectionException)
            ->throw(function (Response $r, RequestException $e) use ($baseUrl) {
                \Illuminate\Support\Facades\Log::info("API Error {$baseUrl}", [
                    'response' => $r,
                    'json_response' => $r->json(),
                    'error' => $e->getMessage(),
                    'status' => $r->status()
                ]);
            })
            ->truncateExceptionsAt(1500);

        $response = null;
        $uri = '';

        switch ($activeCurrencyApi) {
            case 'era1':
                $uri = "/{$apiKey}/latest/{$defaultCurrency}";
                $this->baseUrl = $baseUrl.$uri;
                $response = $pendingRequest->get($uri);
                $data = $response->json();
                $conversionRates = $response->json('conversion_rates');
                $this->processAndSaveRates($data, $conversionRates, $defaultCurrency, 'era1');
                break;
            case 'era2':
                $uri = "/latest?access_key={$apiKey}&base={$defaultCurrency}";
                $this->baseUrl = $baseUrl.$uri;
                $response = $pendingRequest->get($uri);
                $data = $response->json();
                $conversionRates = $response->json('rates');
                $this->processAndSaveRates($data, $conversionRates, $defaultCurrency, 'era2');
                break;
            default:
                throw new Exception("Unsupported currency API: {$activeCurrencyApi}");
        }
    }

    /**
     * Processes the API response and performs bulk upsert and Redis caching.
     */
    private function processAndSaveRates(array $data, array $conversionRates, string $defaultCurrency, string $apiType): void
    {
        $baseCode = $data['base_code'] ?? $data['base'] ?? $defaultCurrency;
        
        $timestamp = null;
        $date = null;
        if ($apiType === 'era1') {
            $timestamp = $data['time_last_update_unix'];
            $date = Date::parse($data['time_last_update_utc'])->format('Y-m-d H:i:s');
        } elseif ($apiType === 'era2') {
            $timestamp = $data['timestamp'];
            $date = $data['date'];
        }

        $databaseInserts = [];
        $redisData = [];

        foreach ($conversionRates as $targetCode => $rate) {
            $databaseInserts[] = [
                'base_code' => $baseCode,
                'target_code' => $targetCode,
                'rate' => $rate,
                'timestamp' => $timestamp,
                'date' => $date,
                'url' => $this->baseUrl,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $redisData["{$baseCode}-{$targetCode}"] = $rate;
        }

        // Use `upsert` for a single database query to insert or update all records.
        DB::table('billing_currency_conversion_rates')->upsert(
            $databaseInserts,
            ['base_code', 'target_code', 'timestamp'],
            ['rate', 'date', 'updated_at']
        );

        // Use `mset` for a single Redis command to cache all rates.
        Redis::mset($redisData);
    }
}
